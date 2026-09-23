window.WelineWidgetAssets.register('checkout-header-checkout-delivery-context-default-0', function (widgetScript) {
(function () {
    'use strict';
    const root = document.querySelector('[data-checkout-delivery-widget]');
    if (!root) return;
    const state = JSON.parse(widgetScript.dataset.v0 || 'null') || {};
    const opts = JSON.parse(widgetScript.dataset.v1 || 'null');
    const panel = root.querySelector('[data-panel]');
    const display = root.querySelector('[data-delivery-display]');
    const message = root.querySelector('[data-message]');
    const addressList = root.querySelector('[data-address-list]');
    const countryCodeInput = root.querySelector('[data-country-code]');
    const countrySearch = root.querySelector('[data-country-search]');
    const countryResults = root.querySelector('[data-country-results]');
    const ADDRESS_CODE = 'checkout-delivery-quick-add';
    const placeholderCountry = JSON.parse(widgetScript.dataset.v2 || 'null');

    let checkoutApi = null;
    let quickAddBound = false;

    async function api() {
        if (checkoutApi) return checkoutApi;
        checkoutApi = await window.Weline.Api.resource('checkout');
        return checkoutApi;
    }

    function text(v) { return v == null ? '' : String(v); }
    function showMsg(msg, tone) {
        if (!message) return;
        message.hidden = !msg;
        message.textContent = text(msg);
        message.dataset.tone = tone || 'info';
    }
    function setDisplay(v) { if (display) display.textContent = text(v || placeholderCountry); }

    function quickAddForm() {
        return root.querySelector('[data-quick-add-form]');
    }

    function openQuickAdd() {
        const details = root.querySelector('[data-quick-add]');
        if (details) details.open = true;
        ensureLazyCaptcha().catch(function () {});
    }

    async function ensureLazyCaptcha() {
        const form = quickAddForm();
        if (!form) return;
        const host = form.querySelector('[data-weline-captcha-lazy]');
        if (!host || host.getAttribute('data-loaded') === '1') return;
        const intent = host.getAttribute('data-intent') || 'checkout.save_delivery_address';
        const formId = host.getAttribute('data-form-id') || form.id || 'checkout-delivery-quick-add-form';
        const result = await (await api()).getDeliveryCaptchaChallenge({
            intent: intent,
            form_id: formId
        }, {silent: true});
        const payload = result && (result.data || result) || {};
        const html = payload.html || '';
        if (!html) {
            throw new Error('captcha_empty');
        }
        host.outerHTML = html;
        if (window.Weline && window.Weline.Form && typeof window.Weline.Form.mount === 'function') {
            window.Weline.Form.mount(form);
        }
    }

    function mountQuickAddRuntime() {
        const form = quickAddForm();
        if (!form) return null;
        if (window.WelineThemeAddress && typeof window.WelineThemeAddress.boot === 'function') {
            window.WelineThemeAddress.boot();
        }
        if (window.Weline && window.Weline.Form && typeof window.Weline.Form.mount === 'function') {
            window.Weline.Form.mount(form);
        } else if (window.WelineFormRuntime && typeof window.WelineFormRuntime.mount === 'function') {
            window.WelineFormRuntime.mount(form);
        }
        return form;
    }

    function applyCascade(values, options) {
        options = options || {};
        const form = mountQuickAddRuntime();
        if (!form) return Promise.resolve(false);
        if (options.open !== false) openQuickAdd();
        if (!window.WelineThemeAddress || typeof window.WelineThemeAddress.applyValues !== 'function') {
            return Promise.resolve(false);
        }
        return window.WelineThemeAddress.applyValues(ADDRESS_CODE, values || {});
    }

    function syncCascadeCountry(countryCode, countryName) {
        const code = text(countryCode).toUpperCase();
        if (!code) return Promise.resolve(false);
        return applyCascade({
            country_code: code,
            country: text(countryName) || code,
        }, {open: false});
    }

    function renderAddresses(ctx) {
        if (!addressList) return;
        const list = Array.isArray(ctx.addresses) ? ctx.addresses : [];
        const hasQuickAdd = !!quickAddForm();
        const emptyText = hasQuickAdd
            ? (widgetScript.dataset.v3)
            : (widgetScript.dataset.v4);
        addressList.innerHTML = list.length ? list.map(function (a) {
            const selected = a.is_selected ? 'is-selected' : '';
            const extra = a.is_anonymous ? (widgetScript.dataset.v5) : (widgetScript.dataset.v6);
            return '<button type="button" class="address-item ' + selected + '" data-id="' + text(a.id) + '">' +
                '<strong>' + text(a.display_label || a.full_address || a.city || a.country_code) + '</strong>' +
                '<small>' + extra + '</small>' +
                '</button>';
        }).join('') : '<p class="address-empty">' + emptyText + '</p>';
    }

    function renderCountries(keyword) {
        if (!countryResults) return;
        const list = Array.isArray(state.countries) ? state.countries : [];
        const query = text(keyword).toLowerCase().trim();
        const filtered = list.filter(function (item) {
            if (!query) return true;
            return text(item.name).toLowerCase().includes(query) || text(item.code).toLowerCase().includes(query);
        }).slice(0, 30);
        countryResults.innerHTML = filtered.map(function (item) {
            return '<button type="button" data-country="' + text(item.code) + '">' + text(item.name) + ' (' + text(item.code) + ')</button>';
        }).join('');
        countryResults.hidden = filtered.length === 0;
    }

    async function refreshCountry(countryCode) {
        const result = await (await api()).setDeliveryCountry({country_code: countryCode}, {silent: true});
        const ctx = result.data || result;
        Object.assign(state, ctx);
        countryCodeInput.value = text(ctx.country_code || '');
        if (countrySearch) countrySearch.value = text(ctx.country_name || '');
        renderAddresses(ctx);
        setDisplay(ctx.display_text);
        await syncCascadeCountry(ctx.country_code, ctx.country_name);
        showMsg((widgetScript.dataset.v7), 'success');
    }

    async function refreshContext() {
        const result = await (await api()).getDeliveryContext({
            country_code: text(countryCodeInput.value || ''),
            address_purpose: 'receiving',
        }, {silent: true});
        const ctx = result.data || result;
        Object.assign(state, ctx);
        renderAddresses(ctx);
        setDisplay(ctx.display_text);
        mountQuickAddRuntime();
        bindQuickAddForm();
        await syncCascadeCountry(ctx.country_code, ctx.country_name);
    }

    function bindQuickAddForm() {
        const form = quickAddForm();
        if (!form || form.dataset.checkoutDeliveryQuickAddBound === '1') return;
        form.dataset.checkoutDeliveryQuickAddBound = '1';
        quickAddBound = true;
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                return;
            }
            const fd = new FormData(form);
            const countryCode = text(fd.get('country_code') || countryCodeInput.value).toUpperCase() || 'CN';
            const address = {
                country_code: countryCode,
                // 国家由弹窗顶部选择；快速新增表单不再渲染国家控件。
                country: text(fd.get('country') || (countrySearch && countrySearch.value) || '').trim() || countryCode,
                contact_name: text(fd.get('contact_name')).trim(),
                contact_phone: text(fd.get('contact_phone')).trim(),
                province: text(fd.get('province')).trim(),
                city: text(fd.get('city')).trim(),
                district: text(fd.get('district')).trim(),
                street: text(fd.get('street')).trim(),
                postal_code: text(fd.get('postal_code')).trim(),
                purpose_source: 'receiving',
                also_use_checkout: fd.get('also_use_checkout') ? 1 : 0,
            };
            const payload = {
                purpose_source: 'receiving',
                also_use_checkout: address.also_use_checkout,
                address: address,
                captcha_provider: text(fd.get('captcha_provider')).trim(),
                captcha_token: text(fd.get('captcha_token')).trim(),
                captcha_response: text(fd.get('captcha_response')).trim(),
                captcha_action: text(fd.get('captcha_action')).trim(),
            };
            try {
                const result = await (await api()).saveDeliveryAddress(payload, {silent: true});
                const ctx = result.data || result;
                Object.assign(state, ctx);
                renderAddresses(ctx);
                setDisplay(ctx.display_text);
                showMsg((widgetScript.dataset.v8), 'success');
                form.reset();
                await syncCascadeCountry(ctx.country_code, ctx.country_name);
            } catch (e) {
                showMsg(e.message || (widgetScript.dataset.v9), 'error');
            }
        });
    }

    root.querySelector('[data-action="toggle"]').addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        if (!panel.hidden) {
            refreshContext().catch(function (e) { showMsg(e.message || (widgetScript.dataset.v10), 'error'); });
            ensureLazyCaptcha().catch(function () {});
        }
    });
    root.querySelector('[data-action="close"]').addEventListener('click', function () { panel.hidden = true; });

    const quickAddDetails = root.querySelector('[data-quick-add]');
    if (quickAddDetails) {
        quickAddDetails.addEventListener('toggle', function () {
            if (quickAddDetails.open) {
                ensureLazyCaptcha().catch(function () {});
            }
        });
    }

    addressList.addEventListener('click', async function (event) {
        const btn = event.target.closest('[data-id]');
        if (!btn) return;
        try {
            const result = await (await api()).selectDeliveryAddress({id: btn.dataset.id}, {silent: true});
            const ctx = result.data || result;
            Object.assign(state, ctx);
            renderAddresses(ctx);
            setDisplay(ctx.display_text);
            showMsg((widgetScript.dataset.v11), 'success');
        } catch (e) {
            showMsg(e.message || (widgetScript.dataset.v12), 'error');
        }
    });

    if (countrySearch && opts.countrySearch) {
        countrySearch.addEventListener('focus', function () { renderCountries(countrySearch.value); });
        countrySearch.addEventListener('input', function () { renderCountries(countrySearch.value); });
    }
    if (countryResults) {
        countryResults.addEventListener('click', function (event) {
            const btn = event.target.closest('[data-country]');
            if (!btn) return;
            refreshCountry(btn.dataset.country).catch(function (e) { showMsg(e.message || (widgetScript.dataset.v13), 'error'); });
            countryResults.hidden = true;
        });
    }

    const autoBtn = root.querySelector('[data-action="auto-detect"]');
    if (autoBtn && opts.autoDetect) {
        autoBtn.addEventListener('click', async function () {
            try {
                if (typeof WelineLocation === 'undefined') {
                    if (window.Weline && typeof window.Weline.load === 'function') {
                        try {
                            await window.Weline.load('location');
                        } catch (e) {
                            openQuickAdd();
                            showMsg((widgetScript.dataset.v14), 'error');
                            return;
                        }
                    } else {
                        openQuickAdd();
                        showMsg((widgetScript.dataset.v15), 'error');
                        return;
                    }
                }

                let position = null;
                try {
                    position = await WelineLocation.getLocation();
                } catch (e) {
                    openQuickAdd();
                    showMsg((widgetScript.dataset.v16), 'error');
                    return;
                }

                let addr = null;
                try {
                    if (window.Weline && typeof window.Weline.load === 'function') {
                        await window.Weline.load('location');
                    }
                    const locationApi = await window.Weline.Api.resource('location');
                    const geo = await locationApi.address({
                        latitude: Number(position.latitude),
                        longitude: Number(position.longitude)
                    }, {silent: true});
                    addr = geo && geo.data ? geo.data : null;
                } catch (error) {
                    openQuickAdd();
                    showMsg((widgetScript.dataset.v17), 'error');
                    return;
                }
                if (!addr) {
                    openQuickAdd();
                    showMsg((widgetScript.dataset.v18), 'error');
                    return;
                }
                const nextCountryCode = text(addr.countryCode || addr.country_code || state.country_code || 'CN').toUpperCase() || 'CN';
                await refreshCountry(nextCountryCode);

                const applied = await applyCascade({
                    country_code: nextCountryCode,
                    country: text(addr.country || countrySearch && countrySearch.value || nextCountryCode).trim(),
                    province: text(addr.province || addr.region || '').trim(),
                    city: text(addr.city || '').trim(),
                    district: text(addr.district || '').trim(),
                });
                const form = quickAddForm();
                if (form) {
                    const streetInput = form.querySelector('[name="street"]');
                    const postalInput = form.querySelector('[name="postal_code"]');
                    if (streetInput) streetInput.value = text(addr.street || '').trim();
                    if (postalInput) postalInput.value = text(addr.postalCode || addr.postal_code || '').trim();
                }
                if (!applied && !form) {
                    showMsg((widgetScript.dataset.v19), 'error');
                    return;
                }
                showMsg((widgetScript.dataset.v20), 'success');
            } catch (e) {
                openQuickAdd();
                showMsg((widgetScript.dataset.v21), 'error');
            }
        });
    }

    setDisplay(state.display_text);
    renderAddresses(state);
    mountQuickAddRuntime();
    bindQuickAddForm();
})();
});
