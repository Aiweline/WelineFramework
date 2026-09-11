(function (global) {
    'use strict';

    var COOKIE_NAME = 'weline_selling_mode';
    var COOKIE_DAYS = 30;
    var APPLY_INTENT_KEY = 'weline_b2b_open_apply';
    var QTY_PREF_KEY = 'weline_b2b_qty_by_mode';

    function resolveWebsiteId(root) {
        var fromRoot = root && root.getAttribute ? String(root.getAttribute('data-website-id') || '').trim() : '';
        if (fromRoot !== '' && /^\d+$/.test(fromRoot)) {
            return fromRoot;
        }
        var site = global.site || {};
        var fromSite = String(site.website_id || site.websiteId || '').trim();
        if (fromSite !== '' && /^\d+$/.test(fromSite)) {
            return fromSite;
        }
        var boot = document.querySelector('[data-website-id]');
        var fromDom = boot ? String(boot.getAttribute('data-website-id') || '').trim() : '';
        if (fromDom !== '' && /^\d+$/.test(fromDom)) {
            return fromDom;
        }
        return '';
    }

    function cookieNames(explicitWebsiteId) {
        var names = [];
        var add = function (name) {
            var value = String(name || '').trim();
            if (value && names.indexOf(value) === -1) {
                names.push(value);
            }
        };
        var websiteId = String(explicitWebsiteId || resolveWebsiteId(null) || '').trim();
        if (websiteId !== '' && /^\d+$/.test(websiteId)) {
            add(COOKIE_NAME + '_w' + websiteId);
        }
        // Keep already-scoped site cookies in sync when site id is missing.
        String(document.cookie || '').split(';').forEach(function (part) {
            var key = String(part.split('=')[0] || '').trim();
            if (/^weline_selling_mode_w\d+$/.test(key)) {
                add(key);
            }
        });
        add(COOKIE_NAME);
        return names;
    }

    function readCookieValue(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + String(name).replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function readCookie() {
        var names = cookieNames();
        for (var i = 0; i < names.length; i += 1) {
            var value = String(readCookieValue(names[i]) || '').toLowerCase();
            if (value === 'toc' || value === 'tob') {
                return value;
            }
        }
        return '';
    }

    function writeCookie(value, root) {
        var mode = value === 'tob' ? 'tob' : 'toc';
        var maxAge = COOKIE_DAYS * 24 * 60 * 60;
        var secure = global.location && String(global.location.protocol) === 'https:' ? '; Secure' : '';
        cookieNames(resolveWebsiteId(root)).forEach(function (name) {
            document.cookie = name + '=' + encodeURIComponent(mode)
                + '; path=/; max-age=' + maxAge + '; SameSite=Lax' + secure;
        });
    }

    function isCustomerLoggedIn(root) {
        try {
            var site = global.site || {};
            if (Number(site.user_id || site.userId || 0) > 0) {
                return true;
            }
        } catch (eSite) {}
        var node = root || document.querySelector('[data-b2b-selling-mode="1"], [data-customer-logged-in]');
        if (node && String(node.getAttribute('data-customer-logged-in') || '0') === '1') {
            return true;
        }
        return !!document.querySelector('[data-customer-logged-in="1"]');
    }

    function preferredMode(root) {
        // Cookie / session win over SSR data-selling-mode: FPC does not fork on
        // preference cookies, so cached HTML often still says toc after user chose tob.
        // Guests: ignore leftover tob cookie on cold load; honor explicit session pick.
        if (!isCustomerLoggedIn(root)) {
            try {
                var explicit = String(global.sessionStorage.getItem('weline_cart_type_explicit') || '').toLowerCase();
                if (explicit === 'toc' || explicit === 'tob') {
                    return explicit;
                }
            } catch (eExplicit) {}
            return 'toc';
        }
        var fromCookie = String(readCookie() || '').toLowerCase();
        if (fromCookie === 'toc' || fromCookie === 'tob') {
            return fromCookie;
        }
        try {
            var fromSession = String(global.sessionStorage.getItem(COOKIE_NAME) || '').toLowerCase();
            if (fromSession === 'toc' || fromSession === 'tob') {
                return fromSession;
            }
        } catch (e) {}
        var fromRoot = String((root && root.getAttribute('data-selling-mode')) || '').toLowerCase();
        if (fromRoot === 'toc' || fromRoot === 'tob') {
            return fromRoot;
        }
        return 'toc';
    }

    function setMode(root, mode, opts) {
        opts = opts && typeof opts === 'object' ? opts : {};
        mode = mode === 'tob' ? 'tob' : 'toc';
        var previous = String(
            (root && root.getAttribute('data-selling-mode'))
            || preferredMode(root)
            || 'toc'
        ).toLowerCase();
        previous = previous === 'tob' ? 'tob' : 'toc';
        if (previous !== mode) {
            rememberCurrentQtys(root, previous);
        }
        writeCookie(mode, root);
        try {
            global.sessionStorage.setItem(COOKIE_NAME, mode);
            global.sessionStorage.setItem('weline_cart_type_explicit', mode);
        } catch (e) {}
        if (root) {
            root.setAttribute('data-selling-mode', mode);
            root.setAttribute('data-cart-type', mode);
        }
        detailRoots(root).forEach(function (detail) {
            detail.setAttribute('data-selling-mode', mode);
            detail.setAttribute('data-cart-type', mode);
            var tiers = detail.querySelector('[data-b2b-qty-tiers="1"], .b2b-qty-tiers');
            if (tiers) {
                tiers.hidden = mode !== 'tob';
            }
        });
        document.documentElement.setAttribute('data-selling-mode', mode);
        syncButtons(mode, root);
        syncQty(mode, root);
        // Adapter from Cart-owned Event uses emit:false to avoid preferCache race.
        if (opts.emit === false) {
            return;
        }
        global.dispatchEvent(new CustomEvent('weline:selling-mode-changed', {
            detail: { selling_mode: mode, cart_type: mode, source: 'b2b-setMode' }
        }));
        // Cart-owned Event: Theme/Cart page listen here; selling-mode-changed stays B2B legacy.
        global.dispatchEvent(new CustomEvent('weline:cart-type-changed', {
            detail: { cart_type: mode, selling_mode: mode, source: 'b2b-setMode' }
        }));
    }

    var FIRST_PROBE_MS = 45000;
    var POLL_INTERVAL_MS = 300000;
    var pollTimers = typeof WeakMap !== 'undefined' ? new WeakMap() : null;

    function uiStateOf(root) {
        return String((root && root.getAttribute('data-membership-ui-state')) || '').toLowerCase();
    }

    function canOpenApply(root) {
        if (!root) {
            return false;
        }
        var state = uiStateOf(root);
        if (state === 'active' || state === 'pending') {
            return false;
        }
        if (String(root.getAttribute('data-has-membership') || '0') === '1' && state !== 'inactive') {
            return false;
        }
        // need_login | can_apply | rejected | inactive | empty legacy
        return true;
    }

    function applyCtaLabel(root, state) {
        if (state === 'pending') {
            return String(root.getAttribute('data-i18n-waiting') || '等待申请通过');
        }
        if (state === 'rejected' || state === 'inactive') {
            return String(root.getAttribute('data-i18n-reapply') || '修改后重新提交');
        }
        return String(root.getAttribute('data-i18n-apply') || '申请批发身份');
    }

    function applyStatusSnapshot(root, status) {
        if (!root || !status || typeof status !== 'object') {
            return;
        }
        var state = String(status.ui_state || '');
        var prev = uiStateOf(root);
        root.setAttribute('data-membership-ui-state', state);
        root.setAttribute('data-application-status', String(status.application_status || ''));
        root.setAttribute('data-should-poll', status.should_poll ? '1' : '0');
        root.setAttribute('data-can-submit', status.can_submit ? '1' : '0');
        root.setAttribute('data-has-membership', status.membership_active ? '1' : '0');
        syncApplyPanels(root, preferredMode(root));
        scheduleMembershipPoll(root);
        if (state === 'active' && prev !== 'active') {
            refreshAfterMembershipActive(root);
        }
    }

    function stopMembershipPoll(root) {
        if (!root || !pollTimers) {
            return;
        }
        var handle = pollTimers.get(root);
        if (handle) {
            if (handle.first) {
                global.clearTimeout(handle.first);
            }
            if (handle.interval) {
                global.clearInterval(handle.interval);
            }
            pollTimers.delete(root);
        }
    }

    function fetchMembershipStatus(root) {
        if (!root || !global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            return Promise.resolve(null);
        }
        var websiteId = Number(root.getAttribute('data-website-id') || 0);
        var api = global.Weline.Api.resource('b2b');
        if (!api || typeof api['membership.status'] !== 'function') {
            return Promise.resolve(null);
        }
        return api['membership.status']({ website_id: websiteId }, { silent: true }).then(function (result) {
            if (!result || result.success === false || result.ok === false || !result.status) {
                return null;
            }
            applyStatusSnapshot(root, result.status);
            return result.status;
        }).catch(function () {
            return null;
        });
    }

    function scheduleMembershipPoll(root) {
        if (!root) {
            return;
        }
        stopMembershipPoll(root);
        if (String(root.getAttribute('data-should-poll') || '0') !== '1') {
            return;
        }
        if (!pollTimers) {
            return;
        }
        var handle = { first: null, interval: null };
        handle.first = global.setTimeout(function () {
            fetchMembershipStatus(root).then(function () {
                if (String(root.getAttribute('data-should-poll') || '0') !== '1') {
                    stopMembershipPoll(root);
                    return;
                }
                handle.interval = global.setInterval(function () {
                    if (String(root.getAttribute('data-should-poll') || '0') !== '1') {
                        stopMembershipPoll(root);
                        return;
                    }
                    fetchMembershipStatus(root);
                }, POLL_INTERVAL_MS);
            });
        }, FIRST_PROBE_MS);
        pollTimers.set(root, handle);
    }

    function refreshAfterMembershipActive(root) {
        if (!root) {
            return;
        }
        try {
            setMode(root, 'tob');
        } catch (eMode) {}
        try {
            global.dispatchEvent(new CustomEvent('weline:selling-mode-changed', {
                detail: { selling_mode: 'tob', cart_type: 'tob', source: 'membership-active' }
            }));
        } catch (eEvt) {}
        try {
            if (global.WelineMiniCart && typeof global.WelineMiniCart.refresh === 'function') {
                global.WelineMiniCart.refresh({ forceNetwork: true });
            }
        } catch (eCart) {}
    }

    function syncApplyPanels(root, mode) {
        if (!root) {
            return;
        }
        var membership = String(root.getAttribute('data-has-membership') || '0') === '1';
        var loggedIn = String(root.getAttribute('data-customer-logged-in') || '0') === '1';
        var state = uiStateOf(root);
        var applyCta = root.querySelector('[data-b2b-open-apply]');
        if (applyCta) {
            var showCta = mode === 'tob' && state !== 'active';
            applyCta.hidden = !showCta;
            applyCta.disabled = state === 'pending';
            applyCta.setAttribute('aria-disabled', state === 'pending' ? 'true' : 'false');
            applyCta.textContent = applyCtaLabel(root, state || (membership ? 'active' : 'can_apply'));
        }
        var guestHint = root.querySelector('[data-b2b-guest-tob-hint]');
        if (guestHint) {
            guestHint.hidden = !(mode === 'tob' && !loggedIn);
        }
        var moqHint = root.querySelector('[data-b2b-tob-moq-hint]');
        if (moqHint) {
            moqHint.hidden = !(mode === 'tob' && loggedIn && membership);
        }
        var guestGate = root.querySelector('[data-b2b-apply-guest-gate]');
        var applyForm = root.querySelector('[data-b2b-apply-form]');
        var applyWrap = root.querySelector('[data-b2b-apply-form-wrap]');
        if (guestGate) {
            guestGate.hidden = !(mode === 'tob' && !loggedIn);
        }
        var showForm = loggedIn && canOpenApply(root) && state !== 'need_login';
        if (applyWrap) {
            applyWrap.hidden = !showForm;
        }
        if (applyForm) {
            applyForm.hidden = !showForm;
        }
    }

    function syncButtons(mode, root) {
        var scope = root || document;
        scope.querySelectorAll('[data-selling-mode-option]').forEach(function (btn) {
            var option = String(btn.getAttribute('data-selling-mode-option') || '').toLowerCase();
            var active = option === mode;
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            btn.setAttribute('data-state', active ? 'active' : 'inactive');
            btn.classList.toggle('is-selected', active);
            btn.removeAttribute('data-variant');
            btn.removeAttribute('data-tone');
        });
        document.querySelectorAll(
            '[data-testid="product-add-to-cart"], [data-testid="product-buy-now"], [data-testid="product-card-add-to-cart"], .product-native-detail__add, .product-native-detail__buy-now, .weline-cart-product-card-add-to-cart [data-action="add"]'
        ).forEach(function (button) {
            button.dataset.sellingMode = mode;
            button.dataset.cartType = mode;
        });
        syncApplyPanels(root, mode);
    }

    function rebuildQtySelect(select, min, step, max, preferred) {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }
        min = Math.max(1, Number(min) || 1);
        step = Math.max(1, Number(step) || 1);
        max = Math.max(min, Number(max) || 30);
        var preferredQty = Math.max(0, Number(preferred) || 0);
        var current = preferredQty > 0 ? preferredQty : (Math.max(min, Number(select.value) || min));
        if (current < min) {
            current = min;
        }
        if (current > max) {
            current = max;
        }
        if ((current - min) % step !== 0) {
            current = min + Math.floor((current - min) / step) * step;
        }
        if (current < min) {
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

    function detailRoots(root) {
        var found = [];
        var seen = typeof WeakSet === 'function' ? new WeakSet() : null;
        function push(el) {
            if (!el || (seen && seen.has(el))) {
                return;
            }
            if (seen) {
                seen.add(el);
            }
            found.push(el);
        }
        if (root && typeof root.closest === 'function') {
            push(root.closest('[data-testid="storefront-product-detail"]'));
        }
        if (!found.length) {
            document.querySelectorAll('[data-testid="storefront-product-detail"]').forEach(push);
        }
        return found;
    }

    function productQtyScopeKey(detail) {
        if (!detail) {
            return 'global';
        }
        var productId = String(
            detail.getAttribute('data-product-id')
            || detail.getAttribute('data-productId')
            || ''
        ).trim();
        return productId !== '' ? productId : 'global';
    }

    function readQtyPrefs() {
        try {
            var raw = global.sessionStorage.getItem(QTY_PREF_KEY);
            if (!raw) {
                return {};
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function writeQtyPrefs(prefs) {
        try {
            global.sessionStorage.setItem(QTY_PREF_KEY, JSON.stringify(prefs || {}));
        } catch (e) {}
    }

    function qtyPrefKey(detail, mode) {
        mode = mode === 'tob' ? 'tob' : 'toc';
        return productQtyScopeKey(detail) + ':' + mode;
    }

    function rememberQty(detail, mode, qty) {
        qty = Math.max(0, Number(qty) || 0);
        if (qty <= 0) {
            return;
        }
        var prefs = readQtyPrefs();
        prefs[qtyPrefKey(detail, mode)] = qty;
        writeQtyPrefs(prefs);
    }

    function recallQty(detail, mode) {
        var prefs = readQtyPrefs();
        var qty = Number(prefs[qtyPrefKey(detail, mode)] || 0) || 0;
        return qty > 0 ? qty : 0;
    }

    function rememberCurrentQtys(root, mode) {
        detailRoots(root).forEach(function (detail) {
            var select = detail.querySelector('[data-testid="product-qty"]');
            if (!select) {
                return;
            }
            rememberQty(detail, mode, Number(select.value) || 0);
        });
    }

    function bindQtyMemory(detail) {
        if (!detail) {
            return;
        }
        var select = detail.querySelector('[data-testid="product-qty"]');
        if (!select || select.getAttribute('data-b2b-qty-mem') === '1') {
            return;
        }
        select.setAttribute('data-b2b-qty-mem', '1');
        select.addEventListener('change', function () {
            var mode = String(
                detail.getAttribute('data-selling-mode')
                || preferredMode(null)
                || 'toc'
            ).toLowerCase();
            rememberQty(detail, mode === 'tob' ? 'tob' : 'toc', Number(select.value) || 0);
        });
    }

    function syncQty(mode, root) {
        detailRoots(root).forEach(function (detail) {
            var select = detail.querySelector('[data-testid="product-qty"]');
            if (!select) {
                return;
            }
            bindQtyMemory(detail);
            var moq = Number((root && root.getAttribute('data-tob-moq')) || select.getAttribute('data-tob-moq') || 5) || 5;
            var step = Number((root && root.getAttribute('data-tob-qty-step')) || select.getAttribute('data-tob-qty-step') || 5) || 5;
            var max = Number(select.getAttribute('data-qty-max') || 30) || 30;
            var preferred = recallQty(detail, mode);
            if (mode === 'tob') {
                rebuildQtySelect(select, moq, step, max, preferred || moq);
            } else {
                rebuildQtySelect(select, 1, 1, max, preferred || 1);
            }
            rememberQty(detail, mode, Number(select.value) || 0);
        });
    }

    function openDrawer(drawer) {
        if (!drawer) {
            return;
        }
        drawer.hidden = false;
        drawer.removeAttribute('hidden');
        drawer.setAttribute('data-state', 'open');
        drawer.setAttribute('aria-hidden', 'false');
        try {
            if (global.Weline && global.Weline.UI && global.Weline.UI.drawer
                && typeof global.Weline.UI.drawer.open === 'function') {
                global.Weline.UI.drawer.open(drawer);
                return;
            }
        } catch (e) {}
    }

    function closeDrawer(drawer) {
        if (!drawer) {
            return;
        }
        try {
            if (global.Weline && global.Weline.UI && global.Weline.UI.drawer
                && typeof global.Weline.UI.drawer.close === 'function') {
                global.Weline.UI.drawer.close(drawer);
            }
        } catch (e) {}
        drawer.setAttribute('data-state', 'closed');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.hidden = true;
        try {
            if (global.WelineSocialQuick && typeof global.WelineSocialQuick.syncFloatingVisibility === 'function') {
                global.WelineSocialQuick.syncFloatingVisibility();
            }
        } catch (eSync) {}
    }

    function markApplyIntent() {
        try {
            global.sessionStorage.setItem(APPLY_INTENT_KEY, '1');
        } catch (e) {}
    }

    function consumeApplyIntent() {
        try {
            var v = global.sessionStorage.getItem(APPLY_INTENT_KEY);
            if (v === '1') {
                global.sessionStorage.removeItem(APPLY_INTENT_KEY);
                return true;
            }
        } catch (e) {}
        return false;
    }

    function revealSocialQuickBar() {
        try {
            if (global.WelineSocialQuick && typeof global.WelineSocialQuick.reveal === 'function') {
                if (global.WelineSocialQuick.reveal()) {
                    return true;
                }
            }
        } catch (eReveal) {}
        var bar = document.querySelector('[data-w-social-quick-fallback-ui]');
        if (bar) {
            bar.hidden = false;
            try {
                bar.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } catch (eScroll) {}
            return true;
        }
        return false;
    }

    function clearGuestLoginFallback(root) {
        if (!root) {
            return;
        }
        var status = root.querySelector('[data-b2b-guest-login-status]');
        if (!status) {
            return;
        }
        status.hidden = true;
        status.setAttribute('hidden', '');
        status.textContent = '';
    }

    function showGuestLoginFallback(root) {
        if (!root) {
            return;
        }
        var gate = root.querySelector('[data-b2b-apply-guest-gate]');
        if (!gate) {
            return;
        }
        var status = gate.querySelector('[data-b2b-guest-login-status]');
        if (!status) {
            status = document.createElement('p');
            status.className = 'b2b-selling-mode__hint product-native-detail__price-label';
            status.setAttribute('data-b2b-guest-login-status', '1');
            status.setAttribute('aria-live', 'polite');
            gate.appendChild(status);
        }
        status.hidden = false;
        status.removeAttribute('hidden');
        var loginUrl = String(root.getAttribute('data-login-url') || '/customer/account/login').trim()
            || '/customer/account/login';
        var returnPath = '';
        try {
            returnPath = String((global.location && global.location.pathname) || '/')
                + String((global.location && global.location.search) || '');
        } catch (ePath) {
            returnPath = '/';
        }
        var sep = loginUrl.indexOf('?') >= 0 ? '&' : '?';
        status.textContent = '';
        status.appendChild(document.createTextNode(
            String(root.getAttribute('data-i18n-login-chooser-missing')
                || '快捷登录暂不可用，请前往登录页。')
        ));
        var link = document.createElement('a');
        link.href = loginUrl + sep + 'redirect_url=' + encodeURIComponent(returnPath);
        link.textContent = String(root.getAttribute('data-i18n-go-login') || '前往登录');
        link.setAttribute('data-testid', 'b2b-apply-guest-login-fallback');
        status.appendChild(document.createTextNode(' '));
        status.appendChild(link);
    }

    function isLoginPanelReady(root) {
        var host = root && root.querySelector('[data-weline-mount="customer/login-panel"]');
        return !!(host && host.getAttribute('data-weline-mount-state') === 'ready'
            && host.querySelector('[data-weline-login-panel]'));
    }

    function requestFrameworkMountScan(root) {
        markApplyIntent();
        var syncSocial = function () {
            try {
                if (global.WelineSocialQuick && typeof global.WelineSocialQuick.syncFloatingVisibility === 'function') {
                    global.WelineSocialQuick.syncFloatingVisibility();
                }
            } catch (eSync) {}
        };
        var finish = function () {
            if (isLoginPanelReady(root)) {
                clearGuestLoginFallback(root);
            } else {
                showGuestLoginFallback(root);
            }
            syncSocial();
        };
        // Framework orchestrates providers; B2B must not call Account.scanMounts.
        // scan() resolving ≠ panel ready (no_provider / async load) — waitFor ready/error.
        if (global.Weline && global.Weline.mount && typeof global.Weline.mount.scan === 'function') {
            var mountApi = global.Weline.mount;
            Promise.resolve(mountApi.scan({ root: root, force: true })).catch(function () { return null; });
            if (typeof mountApi.waitFor === 'function') {
                Promise.resolve(mountApi.waitFor('customer/login-panel', {
                    root: root,
                    timeoutMs: 10000,
                    force: true
                })).then(function () {
                    finish();
                }).catch(function () {
                    finish();
                });
                return;
            }
            Promise.resolve(mountApi.scan({ root: root, force: true })).then(finish).catch(finish);
            return;
        }
        try {
            document.dispatchEvent(new CustomEvent('weline:mount:scan', {
                detail: { force: true, root: root }
            }));
        } catch (eEvt) {}
        setTimeout(finish, 800);
    }

    function openApplyFlow(root) {
        if (!root) {
            return;
        }
        if (!canOpenApply(root)) {
            return;
        }
        // Apply drawer is always ToB context — do not hide guest gate via preferredMode=toc.
        syncApplyPanels(root, 'tob');
        openDrawer(root.querySelector('[data-b2b-apply-drawer]'));
        var loggedIn = String(root.getAttribute('data-customer-logged-in') || '0') === '1';
        if (!loggedIn) {
            // Host declares data-weline-mount; Weline.mount loads provider + fills.
            requestFrameworkMountScan(root);
        }
    }

    async function submitMembership(root, form) {
        var status = root.querySelector('[data-b2b-apply-status]');
        var websiteId = Number(root.getAttribute('data-website-id') || 0);
        var customerId = String(root.getAttribute('data-customer-id') || '').trim();
        var company = String((form.querySelector('[name="company_name"]') || {}).value || '').trim();
        var phone = String((form.querySelector('[name="contact_phone"]') || {}).value || '').trim();
        var notes = String((form.querySelector('[name="notes"]') || {}).value || '').trim();
        if (!customerId || customerId === '0') {
            requestFrameworkMountScan(root);
            throw new Error(String(root.getAttribute('data-i18n-login-required') || '请先登录后再提交申请'));
        }
        if (status) {
            status.textContent = String(root.getAttribute('data-i18n-submitting') || 'Submitting...');
        }
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            throw new Error('api_unavailable');
        }
        var api = global.Weline.Api.resource('b2b');
        var applicationId = String((form.querySelector('[name="application_id"]') || {}).value
            || root.getAttribute('data-application-id')
            || '').trim();
        var payload = {
            customer_id: customerId,
            website_id: websiteId,
            company_name: company,
            contact_phone: phone,
            notes: notes
        };
        if (applicationId !== '') {
            payload.application_id = applicationId;
        }
        var result = await api['membership.submit'](payload, { silent: true });
        if (!result || result.success === false || result.ok === false) {
            throw new Error((result && result.message) || 'submit_failed');
        }
        if (result.application_id) {
            root.setAttribute('data-application-id', String(result.application_id));
        }
        if (status) {
            status.textContent = String(root.getAttribute('data-i18n-submitted') || 'Submitted');
        }
        closeDrawer(root.querySelector('[data-b2b-apply-drawer]'));
    }

    function bindApplyForm(root) {
        var form = root.querySelector('[data-b2b-apply-form]');
        if (!form || form.getAttribute('data-b2b-apply-bound') === '1') {
            return;
        }
        form.setAttribute('data-b2b-apply-bound', '1');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitMembership(root, form).then(function () {
                root.setAttribute('data-membership-ui-state', 'pending');
                root.setAttribute('data-application-status', 'pending');
                root.setAttribute('data-should-poll', '1');
                root.setAttribute('data-can-submit', '0');
                root.setAttribute('data-has-membership', '0');
                syncApplyPanels(root, preferredMode(root));
                scheduleMembershipPoll(root);
                if (root.getAttribute('data-b2b-account-identity') === '1') {
                    var badge = root.querySelector('[data-testid="b2b-account-identity-badge"]');
                    if (badge) {
                        badge.textContent = String(root.getAttribute('data-i18n-submitted') || 'Pending');
                        badge.setAttribute('data-tone', 'warning');
                    }
                    var formWrap = root.querySelector('[data-testid="b2b-account-membership-apply-form"]');
                    if (formWrap) {
                        formWrap.hidden = true;
                    }
                    var hint = document.createElement('p');
                    hint.className = 'b2b-selling-mode__hint';
                    hint.setAttribute('role', 'status');
                    hint.setAttribute('data-testid', 'b2b-account-identity-pending');
                    hint.textContent = String(root.getAttribute('data-i18n-submitted') || 'Submitted');
                    var body = root.querySelector('.account-card__body');
                    if (body) {
                        body.appendChild(hint);
                    }
                }
            }).catch(function (error) {
                var status = root.querySelector('[data-b2b-apply-status]');
                if (status) {
                    status.textContent = (error && error.message) || String(root.getAttribute('data-i18n-submit-failed') || 'Failed');
                }
            });
        });
    }

    function switchToTobAndHome(root) {
        writeCookie('tob', root);
        try {
            global.sessionStorage.setItem(COOKIE_NAME, 'tob');
        } catch (e) {}
        document.documentElement.setAttribute('data-selling-mode', 'tob');
        try {
            global.dispatchEvent(new CustomEvent('weline:selling-mode-changed', {
                detail: { selling_mode: 'tob', cart_type: 'tob' }
            }));
        } catch (e2) {}
        var home = String((root && root.getAttribute('data-home-url')) || '').trim();
        if (!home || home.charAt(0) !== '/') {
            home = '/';
        }
        // Absolute same-origin home avoids relative/hash no-ops on account hash routes.
        var target = String(global.location.origin || '') + home;
        try {
            global.location.assign(target);
        } catch (e3) {
            global.location.href = target;
        }
    }

    function bindAccountIdentity(root) {
        if (!root || root.getAttribute('data-b2b-account-bound') === '1') {
            return;
        }
        root.setAttribute('data-b2b-account-bound', '1');
        bindApplyForm(root);
        root.addEventListener('click', function (event) {
            var raw = event.target;
            var el = raw && raw.nodeType === 3 ? raw.parentElement : raw;
            if (!el || typeof el.closest !== 'function') {
                return;
            }
            if (el.closest('[data-b2b-switch-tob]')) {
                event.preventDefault();
                switchToTobAndHome(root);
            }
        });
    }

    function applyIdentity(identity, scope) {
        identity = identity && typeof identity === 'object' ? identity : {};
        var rootScope = scope && typeof scope.querySelectorAll === 'function' ? scope : document;
        var loggedIn = identity.logged_in === true || identity.logged_in === 1 || identity.logged_in === '1';
        var membership = identity.has_membership === true || identity.has_membership === 1 || identity.has_membership === '1';
        var customerId = String(identity.customer_id || '').trim();
        var websiteId = String(identity.website_id || '').trim();
        rootScope.querySelectorAll('[data-b2b-selling-mode="1"]').forEach(function (root) {
            if (loggedIn) {
                root.setAttribute('data-customer-logged-in', '1');
            }
            if (membership) {
                root.setAttribute('data-has-membership', '1');
            } else if (identity.has_membership === false || identity.has_membership === 0 || identity.has_membership === '0') {
                root.setAttribute('data-has-membership', '0');
            }
            if (customerId !== '' && customerId !== '0') {
                root.setAttribute('data-customer-id', customerId);
            }
            if (websiteId !== '') {
                root.setAttribute('data-website-id', websiteId);
            }
            var mode = preferredMode(root);
            syncButtons(mode, root);
            syncQty(mode, root);
        });
        var boot = bootConfig();
        if (boot) {
            if (loggedIn) {
                boot.setAttribute('data-customer-logged-in', '1');
            }
            if (membership) {
                boot.setAttribute('data-has-membership', '1');
            }
            if (customerId !== '' && customerId !== '0') {
                boot.setAttribute('data-customer-id', customerId);
            }
            if (websiteId !== '') {
                boot.setAttribute('data-website-id', websiteId);
            }
        }
    }

    function hydrateIdentityAttrs(root) {
        if (!root) {
            return;
        }
        var loggedIn = String(root.getAttribute('data-customer-logged-in') || '0') === '1';
        var membership = String(root.getAttribute('data-has-membership') || '0') === '1';
        if (loggedIn && membership) {
            return;
        }
        var sources = document.querySelectorAll(
            '[data-b2b-mini-cart-type="1"], [data-b2b-account-identity="1"], [data-b2b-selling-mode="1"]'
        );
        sources.forEach(function (src) {
            if (src === root) {
                return;
            }
            if (!loggedIn && String(src.getAttribute('data-customer-logged-in') || '0') === '1') {
                root.setAttribute('data-customer-logged-in', '1');
                loggedIn = true;
                var customerId = String(src.getAttribute('data-customer-id') || '').trim();
                if (customerId !== '' && customerId !== '0') {
                    root.setAttribute('data-customer-id', customerId);
                }
            }
            if (!membership && String(src.getAttribute('data-has-membership') || '0') === '1') {
                root.setAttribute('data-has-membership', '1');
                membership = true;
            }
        });
    }

    function bindRoot(root) {
        if (!root || root.getAttribute('data-b2b-selling-bound') === '1') {
            return;
        }
        root.setAttribute('data-b2b-selling-bound', '1');
        hydrateIdentityAttrs(root);
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
                setMode(root, next);
                if (next === 'tob') {
                    if (canOpenApply(root)) {
                        openApplyFlow(root);
                    } else if (uiStateOf(root) === 'active' || String(root.getAttribute('data-has-membership') || '0') === '1') {
                        fetchMembershipStatus(root);
                    }
                }
                return;
            }
            if (event.target.closest('[data-b2b-open-apply]')) {
                openApplyFlow(root);
                return;
            }
            if (event.target.closest('[data-b2b-close-apply]')) {
                closeDrawer(root.querySelector('[data-b2b-apply-drawer]'));
            }
        });

        bindApplyForm(root);
        scheduleMembershipPoll(root);

        var loggedIn = String(root.getAttribute('data-customer-logged-in') || '0') === '1';
        if (loggedIn && canOpenApply(root) && (consumeApplyIntent() || (mode === 'tob' && root.getAttribute('data-auto-open-apply') === '1'))) {
            openApplyFlow(root);
        }
    }

    function bindAllAccountIdentities() {
        document.querySelectorAll('[data-b2b-account-identity="1"]').forEach(bindAccountIdentity);
    }

    function bootConfig() {
        return document.querySelector('[data-b2b-mini-cart-type="1"]');
    }

    function i18n(key, fallback) {
        var boot = bootConfig();
        if (!boot) {
            return fallback;
        }
        return String(boot.getAttribute(key) || fallback);
    }

    function ensureMiniCartExtrasVisible(root, mode) {
        if (!root) {
            return;
        }
        mode = mode === 'tob' ? 'tob' : 'toc';
        // 批发/零售共用零售页签结构：不堆叠重写；批发默认切到留言页签，避免只看见禁用券像「留言丢了」。
        root.querySelectorAll(
            '[data-w-mini-cart="1"] .mini-cart-drawer__extras,'
            + ' [data-w-mini-cart="1"] [data-wslot="footer-extras"],'
            + ' [data-cart-summary-extras="1"],'
            + ' .weline-cart-shell__summary-extras,'
            + ' .weline-checkout__extras,'
            + ' [data-weline-checkout] .mini-cart-drawer__extras'
        ).forEach(function (extras) {
            extras.hidden = false;
            extras.removeAttribute('hidden');
            if (extras.style && extras.style.display === 'none') {
                extras.style.removeProperty('display');
            }
            extras.classList.remove('is-tob-extras-stack');
        });
        if (global.WelineMiniCartExtras && typeof global.WelineMiniCartExtras.boot === 'function') {
            try {
                global.WelineMiniCartExtras.boot();
            } catch (e) {
                // ignore
            }
        }
        // boot 之后再选页签：批发仅首次默认切到留言；之后尊重用户点击（优惠券页签可再点开看禁用态）。
        root.querySelectorAll(
            '[data-w-mini-cart="1"] .mini-cart-drawer__extras,'
            + ' [data-w-mini-cart="1"] [data-wslot="footer-extras"],'
            + ' [data-cart-summary-extras="1"],'
            + ' .weline-cart-shell__summary-extras,'
            + ' .weline-checkout__extras,'
            + ' [data-weline-checkout] .mini-cart-drawer__extras'
        ).forEach(function (extras) {
            var shell = extras.querySelector('[data-mini-cart-extras-tabs]');
            if (!shell) {
                return;
            }
            var tabs = shell.querySelectorAll('[data-mini-cart-extras-tab]');
            var panels = shell.querySelectorAll('[data-mini-cart-extras-panel]');
            if (!tabs.length || !panels.length) {
                return;
            }
            var activeIdx = 0;
            var hasActive = false;
            tabs.forEach(function (tab, i) {
                if (tab.classList.contains('is-active')) {
                    activeIdx = i;
                    hasActive = true;
                }
            });
            if (mode === 'tob') {
                var alreadyDefaulted = shell.getAttribute('data-b2b-tob-default-tab') === '1';
                if (!alreadyDefaulted) {
                    panels.forEach(function (panel, i) {
                        if (
                            panel.querySelector(
                                '.w-order-notice, [data-testid="order-notice-widget"],'
                                + ' [data-wslot="cart-summary-note"], [data-wslot*="note"]'
                            )
                        ) {
                            activeIdx = i;
                        }
                    });
                    shell.setAttribute('data-b2b-tob-default-tab', '1');
                } else if (!hasActive) {
                    activeIdx = Number(shell.getAttribute('data-active-tab') || '0') || 0;
                }
            } else {
                shell.removeAttribute('data-b2b-tob-default-tab');
                if (!hasActive) {
                    activeIdx = 0;
                }
            }
            if (activeIdx < 0 || activeIdx >= tabs.length) {
                activeIdx = 0;
            }
            tabs.forEach(function (tab, i) {
                var on = i === activeIdx;
                tab.classList.toggle('is-active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                tab.tabIndex = on ? 0 : -1;
            });
            panels.forEach(function (panel, i) {
                var on = i === activeIdx;
                panel.classList.toggle('is-active', on);
                panel.hidden = !on;
            });
            shell.setAttribute('data-active-tab', String(activeIdx));
        });
    }

    function syncMiniCartCouponAvailability(root, mode) {
        if (!root) {
            return;
        }
        var unavailable = mode === 'tob';
        var message = i18n('data-i18n-coupon-tob-unavailable', '批发不可用');
        ensureMiniCartExtrasVisible(root, mode);
        root.querySelectorAll(
            '.w-marketing-checkout-coupon--mini-cart, [data-testid="marketing-mini-cart-coupon"],'
            + ' .w-marketing-checkout-coupon, [data-testid="marketing-checkout-coupon"]'
        ).forEach(function (coupon) {
            coupon.hidden = false;
            coupon.removeAttribute('hidden');
            coupon.classList.toggle('is-tob-unavailable', unavailable);
            coupon.setAttribute('aria-disabled', unavailable ? 'true' : 'false');
            coupon.setAttribute('data-b2b-coupon-unavailable', unavailable ? '1' : '0');
            coupon.querySelectorAll('[data-marketing-coupon-input], [data-marketing-coupon-apply]').forEach(function (el) {
                if ('disabled' in el) {
                    el.disabled = unavailable;
                }
                if (unavailable) {
                    el.setAttribute('tabindex', '-1');
                } else {
                    el.removeAttribute('tabindex');
                }
            });
            if (unavailable) {
                coupon.querySelectorAll('[data-marketing-coupon-tag]').forEach(function (tag) {
                    tag.remove();
                });
                var tagsEl = coupon.querySelector('[data-marketing-coupon-tags]');
                if (tagsEl) {
                    tagsEl.innerHTML = '';
                    tagsEl.hidden = true;
                }
                coupon.classList.remove('is-applied');
            }
            var msg = coupon.querySelector('[data-marketing-coupon-message]');
            if (msg) {
                if (unavailable) {
                    msg.textContent = message;
                    msg.setAttribute('data-b2b-coupon-msg', '1');
                    msg.hidden = false;
                    msg.removeAttribute('hidden');
                } else if (msg.getAttribute('data-b2b-coupon-msg') === '1') {
                    msg.textContent = '';
                    msg.removeAttribute('data-b2b-coupon-msg');
                }
            }
        });
        // 留言始终可用：显式清掉误伤属性（若曾被其它脚本禁用）
        root.querySelectorAll('.w-order-notice, [data-testid="order-notice-widget"]').forEach(function (notice) {
            notice.hidden = false;
            notice.removeAttribute('hidden');
            notice.classList.remove('is-tob-unavailable');
            notice.removeAttribute('aria-disabled');
            notice.querySelectorAll('[data-order-notice-input]').forEach(function (el) {
                if ('disabled' in el) {
                    el.disabled = false;
                }
                el.removeAttribute('tabindex');
            });
            // 迷你车页签模式：标签仍由 tab 文案承担，与零售一致
            notice.querySelectorAll('.w-order-notice__label').forEach(function (label) {
                if (label.hasAttribute('data-mini-cart-tab-label-source') && notice.classList.contains('w-order-notice--mini-cart')) {
                    label.hidden = true;
                }
            });
        });
    }

    function syncCheckoutChrome(mode) {
        mode = mode === 'tob' ? 'tob' : 'toc';
        document.querySelectorAll('[data-weline-checkout], .weline-checkout').forEach(function (root) {
            ensureMiniCartExtrasVisible(root, mode);
            if (global.WelineB2BCheckoutTob && typeof global.WelineB2BCheckoutTob.applyCartType === 'function') {
                try {
                    global.WelineB2BCheckoutTob.applyCartType(root, mode);
                    return;
                } catch (e) {
                    // fall through
                }
            }
            root.setAttribute('data-cart-type', mode);
            root.classList.toggle('is-cart-type-tob', mode === 'tob');
            syncMiniCartCouponAvailability(root, mode);
        });
    }

    function syncMiniCartChrome(mode) {
        mode = mode === 'tob' ? 'tob' : 'toc';
        var shortLabel = mode === 'tob' ? i18n('data-i18n-tob', '批发') : i18n('data-i18n-toc', '零售');
        var cartLabel = mode === 'tob' ? i18n('data-i18n-tob-cart', '批发车') : i18n('data-i18n-toc-cart', '零售车');
        document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
            root.setAttribute('data-cart-type', mode);
            root.classList.toggle('is-cart-type-tob', mode === 'tob');
            if (mode === 'tob') {
                var gate = String(root.getAttribute('data-cart-gate') || '');
                if (gate === 'login') {
                    root.setAttribute('data-empty-message', i18n('data-i18n-empty-tob-login', '批发车需登录后使用'));
                } else if (gate === 'membership') {
                    root.setAttribute('data-empty-message', i18n('data-i18n-empty-tob-membership', '开通批发身份后可使用批发车'));
                } else {
                    root.setAttribute('data-empty-message', i18n('data-i18n-empty-tob', '批发车是空的'));
                }
            } else {
                root.removeAttribute('data-empty-message');
            }
            root.querySelectorAll('[data-mini-cart-type-caption]').forEach(function (el) {
                el.hidden = false;
                el.textContent = shortLabel;
                var meta = el.closest('[data-mini-cart-meta]');
                if (meta && !meta.querySelector('.cart-meta__sep') && meta.querySelector('[data-cart-subtotal-text]')) {
                    var sep = document.createElement('span');
                    sep.className = 'cart-meta__sep';
                    sep.setAttribute('aria-hidden', 'true');
                    sep.textContent = ' · ';
                    el.after(sep);
                }
            });
            root.querySelectorAll('[data-mini-cart-title]').forEach(function (el) {
                el.textContent = cartLabel;
            });
            root.querySelectorAll('[data-b2b-mini-cart-type-option]').forEach(function (btn) {
                var option = String(btn.getAttribute('data-b2b-mini-cart-type-option') || '').toLowerCase();
                var active = option === mode;
                btn.classList.toggle('is-selected', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                btn.setAttribute('data-state', active ? 'active' : 'inactive');
            });
            syncMiniCartCouponAvailability(root, mode);
        });
        syncCheckoutChrome(mode);
    }

    function ensureTypeSeg(host, opts) {
        opts = opts && typeof opts === 'object' ? opts : {};
        if (!host || host.querySelector('[data-b2b-mini-cart-type-seg]')) {
            return;
        }
        var seg = document.createElement('div');
        seg.className = 'b2b-mini-cart-type-seg' + (opts.cartPage ? ' b2b-cart-page-type-seg' : '');
        seg.setAttribute('role', 'group');
        seg.setAttribute('aria-label', i18n('data-i18n-toc-cart', '零售车') + ' / ' + i18n('data-i18n-tob-cart', '批发车'));
        seg.setAttribute('data-b2b-mini-cart-type-seg', '1');
        if (opts.cartPage) {
            seg.setAttribute('data-b2b-cart-page-type-seg', '1');
        }
        seg.setAttribute('data-testid', opts.cartPage ? 'cart-page-type-segment' : 'mini-cart-type-segment');

        function makeBtn(code, label, testId) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'b2b-mini-cart-type-option';
            btn.setAttribute('data-b2b-mini-cart-type-option', code);
            // Cart-owned chrome selector (Theme/Cart call WelineCart.requestCartType → click this).
            btn.setAttribute('data-cart-type-option', code);
            btn.setAttribute('data-testid', testId);
            btn.textContent = label;
            return btn;
        }

        seg.appendChild(makeBtn('toc', i18n('data-i18n-toc-cart', '零售车'), opts.cartPage ? 'cart-page-type-toc' : 'mini-cart-type-toc'));
        seg.appendChild(makeBtn('tob', i18n('data-i18n-tob-cart', '批发车'), opts.cartPage ? 'cart-page-type-tob' : 'mini-cart-type-tob'));
        host.appendChild(seg);

        seg.addEventListener('click', function (event) {
            var btn = event.target && event.target.closest
                ? event.target.closest('[data-b2b-mini-cart-type-option]')
                : null;
            if (!btn || !seg.contains(btn)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            var next = String(btn.getAttribute('data-b2b-mini-cart-type-option') || '').toLowerCase();
            if (next !== 'toc' && next !== 'tob') {
                return;
            }
            setMode(null, next);
            syncMiniCartChrome(next);
            syncCartPageChrome(next);
            // Prefer typed local summary_cache.{toc|tob}; no cart mutation → no forced getCart.
            if (global.Weline && global.Weline.MiniCart && typeof global.Weline.MiniCart.refresh === 'function') {
                global.Weline.MiniCart.refresh({ forceNetwork: false });
            }
            // setMode already dispatches weline:selling-mode-changed; cart page uses typed cache first.
        });
    }

    function ensureMiniCartTypeSeg(root) {
        var host = root && root.querySelector('[data-mini-cart-type-host]');
        ensureTypeSeg(host, { cartPage: false });
    }

    function ensureCartPageTypeSeg(root) {
        var host = root && root.querySelector('[data-cart-page-type-host]');
        ensureTypeSeg(host, { cartPage: true });
    }

    function syncCartPageChrome(mode) {
        mode = mode === 'tob' ? 'tob' : 'toc';
        var cartLabel = mode === 'tob' ? i18n('data-i18n-tob-cart', '批发车') : i18n('data-i18n-toc-cart', '零售车');
        document.querySelectorAll('[data-weline-cart]').forEach(function (root) {
            root.setAttribute('data-cart-type', mode);
            root.classList.toggle('is-cart-type-tob', mode === 'tob');
            root.querySelectorAll('[data-cart-page-title]').forEach(function (el) {
                el.textContent = cartLabel;
            });
            root.querySelectorAll('[data-b2b-mini-cart-type-option]').forEach(function (btn) {
                var option = String(btn.getAttribute('data-b2b-mini-cart-type-option') || '').toLowerCase();
                var active = option === mode;
                btn.classList.toggle('is-selected', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                btn.setAttribute('data-state', active ? 'active' : 'inactive');
            });
            syncMiniCartCouponAvailability(root, mode);
        });
    }

    var didInitialMiniCartTypedRefresh = false;
    var enhanceMiniCartsBusy = false;

    function enhanceMiniCarts(opts) {
        opts = opts && typeof opts === 'object' ? opts : {};
        // Default: inject/sync chrome only. Network refresh only on explicit opts.refresh
        // or when data-cart-type mismatches preferred mode (once per mismatch via event).
        var wantRefresh = opts.refresh === true;
        if (enhanceMiniCartsBusy) {
            return;
        }
        enhanceMiniCartsBusy = true;
        try {
            document.querySelectorAll('[data-w-mini-cart="1"]').forEach(function (root) {
                ensureMiniCartTypeSeg(root);
            });
            document.querySelectorAll('[data-weline-cart]').forEach(function (root) {
                ensureCartPageTypeSeg(root);
            });
            var mode = preferredMode(null);
            // Compare BEFORE syncCartPageChrome mutates data-cart-type; otherwise mismatch
            // is erased and wholesale chrome can keep retail line items.
            var needsCartReload = false;
            document.querySelectorAll('[data-weline-cart], [data-w-mini-cart="1"]').forEach(function (root) {
                var current = String(root.getAttribute('data-cart-type') || '').toLowerCase();
                if (current && current !== mode) {
                    needsCartReload = true;
                }
            });
            syncMiniCartChrome(mode);
            syncCartPageChrome(mode);
            if (needsCartReload) {
                // MiniCart / cart page listen to this and refresh typed carts once.
                didInitialMiniCartTypedRefresh = true;
                global.dispatchEvent(new CustomEvent('weline:selling-mode-changed', {
                    detail: { selling_mode: mode, cart_type: mode, source: 'enhanceMiniCarts' }
                }));
                return;
            }
            // First boot only: paint preferred type from local bucket; network only if cache miss.
            if (wantRefresh && !didInitialMiniCartTypedRefresh) {
                didInitialMiniCartTypedRefresh = true;
                if (global.Weline && global.Weline.MiniCart && typeof global.Weline.MiniCart.refresh === 'function') {
                    global.Weline.MiniCart.refresh({ forceNetwork: false });
                }
            }
        } finally {
            enhanceMiniCartsBusy = false;
        }
    }

    function init() {
        // Promote legacy bare cookie → website-scoped name so PHP Cookie::get sees it.
        var persisted = preferredMode(null);
        if (persisted === 'toc' || persisted === 'tob') {
            writeCookie(persisted, null);
        }
        document.querySelectorAll('[data-b2b-selling-mode="1"]').forEach(bindRoot);
        bindAllAccountIdentities();
        function bindAllSellingModes() {
            document.querySelectorAll('[data-b2b-selling-mode="1"]').forEach(bindRoot);
        }
        global.WelineB2BSellingMode = {
            preferredMode: function () {
                return preferredMode(document.querySelector('[data-b2b-selling-mode="1"]'));
            },
            openApply: function () {
                var root = document.querySelector('[data-b2b-selling-mode="1"]');
                if (root) {
                    openApplyFlow(root);
                }
            },
            bindAll: bindAllSellingModes,
            applyIdentity: applyIdentity,
            enhanceMiniCarts: enhanceMiniCarts,
            syncMiniCartChrome: syncMiniCartChrome,
            syncCheckoutChrome: syncCheckoutChrome,
            syncCouponAvailability: syncMiniCartCouponAvailability,
            i18n: i18n,
            bindAccountIdentities: bindAllAccountIdentities,
            cookieName: COOKIE_NAME,
            cookieNames: cookieNames,
            readCookie: readCookie,
            writeCookie: writeCookie
        };
        enhanceMiniCarts({ refresh: true });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') {
                return;
            }
            document.querySelectorAll('[data-b2b-selling-mode="1"], [data-b2b-account-identity="1"]').forEach(function (root) {
                fetchMembershipStatus(root);
            });
        });
        global.addEventListener('weline:selling-mode-changed', function (event) {
            var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
            // Ignore self-echo from enhanceMiniCarts to avoid chrome/DOM churn loops.
            if (detail.source === 'enhanceMiniCarts') {
                return;
            }
            var mode = String(detail.selling_mode || detail.cart_type || preferredMode(null)).toLowerCase();
            syncMiniCartChrome(mode === 'tob' ? 'tob' : 'toc');
            syncCartPageChrome(mode === 'tob' ? 'tob' : 'toc');
            syncCheckoutChrome(mode === 'tob' ? 'tob' : 'toc');
        });
        // Cart-owned switch Event → adapt into setMode (Theme/Cart must not click B2B DOM).
        global.addEventListener('weline:cart-type-changed', function (event) {
            var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
            if (detail.source === 'b2b-setMode' || detail.source === 'enhanceMiniCarts') {
                return;
            }
            var mode = String(detail.cart_type || detail.selling_mode || '').toLowerCase();
            if (mode !== 'toc' && mode !== 'tob') {
                return;
            }
            // Silent adapt: keep forceNetwork on the original Cart Event; do not re-emit.
            setMode(document.querySelector('[data-b2b-selling-mode="1"]'), mode, { emit: false });
            syncMiniCartChrome(mode);
            syncCartPageChrome(mode);
            syncCheckoutChrome(mode);
        });
        global.addEventListener('weline:cart-updated', function () {
            syncMiniCartChrome(preferredMode(null));
            syncCheckoutChrome(preferredMode(null));
        });
        global.addEventListener('weline:account-sidebar-content-loaded', function () {
            bindAllAccountIdentities();
        });
        // Mini-cart roots / lazy account sections / quick-add panel may hydrate after this script.
        // Do NOT forceNetwork here: applySummary/DOM chrome sync would re-enter the observer
        // and storm cart.getCart.
        if (global.MutationObserver) {
            var pending = null;
            var observer = new MutationObserver(function () {
                if (pending) {
                    return;
                }
                pending = global.setTimeout(function () {
                    pending = null;
                    bindAllSellingModes();
                    enhanceMiniCarts({ refresh: false });
                    bindAllAccountIdentities();
                }, 80);
            });
            observer.observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
