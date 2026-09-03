(function () {
    'use strict';
    var root = document.querySelector('[data-shipping-checkout-address]');
    if (!root) {
        return;
    }

    var ADDRESS_CODE = 'checkout-shipping-address';
    var form = root.closest('form');
    var editor = root.querySelector('[data-shipping-editor]');
    var billingEditor = root.querySelector('[data-billing-editor]');
    var savedBox = root.querySelector('[data-saved-addresses]');
    var initial = {};
    try {
        initial = JSON.parse(root.getAttribute('data-initial-address') || '{}') || {};
    } catch (e) {
        initial = {};
    }

    function text(v) {
        return v == null ? '' : String(v);
    }

    function mode() {
        return text(root.getAttribute('data-mode') || 'new');
    }

    function setMode(next) {
        root.setAttribute('data-mode', next);
        if (!editor) {
            return;
        }
        if (next === 'collapsed') {
            editor.setAttribute('hidden', 'hidden');
            setShippingRequired(false);
        } else {
            editor.removeAttribute('hidden');
            setShippingRequired(true);
        }
    }

    function setShippingRequired(on) {
        root.querySelectorAll('[data-shipping-field][name="name"],[data-shipping-field][name="phone"],[data-shipping-field][name="email"],[data-shipping-field][name="address1"]').forEach(function (input) {
            if (on) {
                input.setAttribute('required', 'required');
            } else {
                input.removeAttribute('required');
            }
        });
    }

    function setField(name, value, scope) {
        if (!form) {
            return;
        }
        var input = scope
            ? scope.querySelector('[name="' + name + '"]')
            : form.querySelector('[name="' + name + '"]');
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

    function readShippingSnapshot() {
        if (!form) {
            return {};
        }
        var data = new FormData(form);
        return {
            name: text(data.get('name')).trim(),
            phone: text(data.get('phone')).trim(),
            email: text(data.get('email')).trim(),
            country_code: text(data.get('country_code')).trim().toUpperCase() || 'CN',
            country: text(data.get('country')).trim(),
            province: text(data.get('province')).trim(),
            city: text(data.get('city')).trim(),
            district: text(data.get('district')).trim(),
            address1: text(data.get('address1') || data.get('street')).trim(),
            postal_code: text(data.get('postal_code')).trim()
        };
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

    function fillShipping(address, opts) {
        if (!address || typeof address !== 'object') {
            return;
        }
        var clearEmpty = !!(opts && opts.clearEmpty);
        ['name', 'phone', 'email', 'address1', 'postal_code'].forEach(function (field) {
            if (address[field] != null && text(address[field]).trim() !== '') {
                setField(field, address[field]);
            } else if (clearEmpty) {
                setField(field, '');
            }
        });
        if (address.street && text(address.address1 || '').trim() === '') {
            setField('address1', address.street);
        }
        applyCascade(address);
    }

    function markSelected(id) {
        root.setAttribute('data-selected-id', text(id));
        root.querySelectorAll('[data-address-card]').forEach(function (card) {
            var on = text(card.getAttribute('data-address-id')) === text(id);
            card.classList.toggle('is-selected', on);
            var radio = card.querySelector('[data-address-radio]');
            if (radio) {
                radio.checked = on;
            }
        });
    }

    async function selectSaved(card) {
        var id = text(card.getAttribute('data-address-id'));
        var payload = {};
        try {
            payload = JSON.parse(card.getAttribute('data-address-json') || '{}') || {};
        } catch (e) {
            payload = {};
        }
        markSelected(id);
        fillShipping(payload);
        setMode('collapsed');
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
                fillShipping(Object.assign({}, payload, ctx.checkout_address, {
                    district: (ctx.selected && ctx.selected.district) || payload.district || ''
                }));
            }
        } catch (e) {
            // 本地回填已完成
        }
    }

    function openEdit(card) {
        if (card) {
            selectSaved(card);
        }
        setMode('edit');
        if (editor && typeof editor.scrollIntoView === 'function') {
            editor.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }
    }

    function openNew() {
        root.querySelectorAll('[data-address-radio]').forEach(function (radio) {
            radio.checked = false;
        });
        root.querySelectorAll('[data-address-card]').forEach(function (card) {
            card.classList.remove('is-selected');
        });
        root.setAttribute('data-selected-id', '');
        fillShipping({
            name: '',
            phone: '',
            email: text(initial.email || ''),
            country_code: 'CN',
            country: '',
            province: '',
            city: '',
            district: '',
            address1: '',
            postal_code: ''
        }, {clearEmpty: true});
        setMode('new');
        if (editor && typeof editor.scrollIntoView === 'function') {
            editor.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }
    }

    function cancelEdit() {
        var selectedId = text(root.getAttribute('data-selected-id'));
        var card = selectedId
            ? root.querySelector('[data-address-card][data-address-id="' + selectedId.replace(/"/g, '') + '"]')
            : root.querySelector('[data-address-card].is-selected');
        if (card) {
            selectSaved(card);
            return;
        }
        if (text(root.getAttribute('data-has-saved')) === '1') {
            var first = root.querySelector('[data-address-card]');
            if (first) {
                selectSaved(first);
                return;
            }
        }
        setMode('new');
    }

    function useEditedAddress() {
        if (text(root.getAttribute('data-has-saved')) === '1') {
            setMode('collapsed');
        } else {
            setMode('new');
        }
    }

    function setBillingEnabled(on) {
        if (!billingEditor) {
            return;
        }
        billingEditor.querySelectorAll('[data-billing-field]').forEach(function (input) {
            input.disabled = !on;
            if (on) {
                input.removeAttribute('disabled');
            } else {
                input.setAttribute('disabled', 'disabled');
            }
        });
        if (on) {
            billingEditor.removeAttribute('hidden');
        } else {
            billingEditor.setAttribute('hidden', 'hidden');
        }
    }

    function syncBillingFromShipping() {
        var snap = readShippingSnapshot();
        setField('billing_name', snap.name, billingEditor);
        setField('billing_phone', snap.phone, billingEditor);
        setField('billing_email', snap.email, billingEditor);
        setField('billing_country_code', snap.country_code || 'CN', billingEditor);
        setField('billing_province', snap.province, billingEditor);
        setField('billing_city', snap.city, billingEditor);
        setField('billing_district', snap.district, billingEditor);
        setField('billing_address1', snap.address1, billingEditor);
        setField('billing_postal_code', snap.postal_code, billingEditor);
    }

    function onBillingSameChange() {
        var box = root.querySelector('[data-billing-same]');
        var same = !box || box.checked;
        if (same) {
            setBillingEnabled(false);
            return;
        }
        syncBillingFromShipping();
        setBillingEnabled(true);
    }

    function bootAddress() {
        if (window.WelineThemeAddress && typeof window.WelineThemeAddress.boot === 'function') {
            window.WelineThemeAddress.boot();
        }
        return applyCascade(initial);
    }

    if (savedBox) {
        savedBox.addEventListener('change', function (event) {
            var radio = event.target && event.target.closest ? event.target.closest('[data-address-radio]') : null;
            if (!radio || !savedBox.contains(radio)) {
                return;
            }
            var card = radio.closest('[data-address-card]');
            if (card) {
                selectSaved(card);
            }
        });
        savedBox.addEventListener('click', function (event) {
            var editBtn = event.target.closest('[data-edit-address]');
            if (editBtn && savedBox.contains(editBtn)) {
                event.preventDefault();
                event.stopPropagation();
                var card = editBtn.closest('[data-address-card]');
                openEdit(card);
                return;
            }
        });
    }

    var addBtn = root.querySelector('[data-add-address]');
    if (addBtn) {
        addBtn.addEventListener('click', function (event) {
            event.preventDefault();
            openNew();
        });
    }
    var cancelBtn = root.querySelector('[data-cancel-edit]');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function (event) {
            event.preventDefault();
            cancelEdit();
        });
    }
    var useBtn = root.querySelector('[data-use-edited-address]');
    if (useBtn) {
        useBtn.addEventListener('click', function (event) {
            event.preventDefault();
            useEditedAddress();
        });
    }
    root.addEventListener('change', function (event) {
        var target = event.target;
        if (target && target.matches && target.matches('[data-billing-same]')) {
            onBillingSameChange();
        }
    });
    root.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.closest && target.closest('[data-billing-same]')) {
            window.setTimeout(onBillingSameChange, 0);
        }
    });

    setBillingEnabled(false);
    if (text(root.getAttribute('data-has-saved')) === '1') {
        setMode('collapsed');
        setShippingRequired(false);
    } else {
        setMode('new');
        setShippingRequired(true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bootAddress();
        });
    } else {
        bootAddress();
    }
})();
