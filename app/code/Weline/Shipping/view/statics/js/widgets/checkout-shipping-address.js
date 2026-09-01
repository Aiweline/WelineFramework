(function () {
    'use strict';
    var root = document.querySelector('[data-shipping-checkout-address]');
    if (!root) {
        return;
    }
    var ADDRESS_CODE = 'checkout-shipping-address';
    var form = root.closest('form');
    var initial = {};
    try {
        initial = JSON.parse(root.getAttribute('data-initial-address') || '{}') || {};
    } catch (e) {
        initial = {};
    }

    function text(v) {
        return v == null ? '' : String(v);
    }

    function setField(name, value) {
        if (!form) {
            return;
        }
        var input = form.querySelector('[name="' + name + '"]');
        if (!input) {
            return;
        }
        var next = text(value).trim();
        if (input.value === next) {
            return;
        }
        input.value = next;
        input.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function applyCascade(address) {
        if (!window.WelineThemeAddress || typeof window.WelineThemeAddress.applyValues !== 'function') {
            return Promise.resolve(false);
        }
        return window.WelineThemeAddress.applyValues(ADDRESS_CODE, {
            country_code: text(address.country_code).trim().toUpperCase(),
            country: text(address.country || address.country_name || address.country_code).trim(),
            province: text(address.province).trim(),
            city: text(address.city).trim(),
            district: text(address.district).trim()
        });
    }

    function fillAddress(address) {
        if (!address || typeof address !== 'object') {
            return;
        }
        ['name', 'phone', 'email', 'address1', 'postal_code'].forEach(function (field) {
            if (address[field] != null && text(address[field]).trim() !== '') {
                setField(field, address[field]);
            }
        });
        if (address.street && text(address.address1).trim() === '') {
            setField('address1', address.street);
        }
        applyCascade(address);
    }

    function bootAddress() {
        if (window.WelineThemeAddress && typeof window.WelineThemeAddress.boot === 'function') {
            window.WelineThemeAddress.boot();
        }
        return applyCascade(initial);
    }

    function markSelected(id) {
        root.querySelectorAll('[data-address-id]').forEach(function (btn) {
            var on = text(btn.getAttribute('data-address-id')) === text(id);
            btn.classList.toggle('is-selected', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    async function selectSaved(btn) {
        var id = text(btn.getAttribute('data-address-id'));
        var payload = {};
        try {
            payload = JSON.parse(btn.getAttribute('data-address-json') || '{}') || {};
        } catch (e) {
            payload = {};
        }
        markSelected(id);
        fillAddress(payload);
        if (!id || !window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return;
        }
        try {
            var api = await window.Weline.Api.resource('checkout');
            if (!api || typeof api.selectDeliveryAddress !== 'function') {
                return;
            }
            var result = await api.selectDeliveryAddress({id: id}, {silent: true});
            var ctx = (result && (result.data || result)) || {};
            if (ctx.checkout_address) {
                fillAddress(Object.assign({}, payload, ctx.checkout_address, {
                    district: (ctx.selected && ctx.selected.district) || payload.district || ''
                }));
            }
        } catch (e) {
            // 本地回填已完成
        }
    }

    var tags = root.querySelector('[data-saved-addresses]');
    if (tags) {
        tags.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-address-id]');
            if (!btn || !tags.contains(btn)) {
                return;
            }
            selectSaved(btn);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bootAddress();
        });
    } else {
        bootAddress();
    }
})();
