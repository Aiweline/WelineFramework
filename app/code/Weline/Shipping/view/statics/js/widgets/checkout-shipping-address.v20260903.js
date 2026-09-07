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
    var i18n = {};
    try {
        i18n = JSON.parse(root.getAttribute('data-i18n') || '{}') || {};
    } catch (e) {
        i18n = {};
    }

    function t(key, fallback) {
        var value = text(i18n[key]).trim();
        return value !== '' ? value : text(fallback);
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
            street: text(data.get('street')).trim(),
            street_id: text(data.get('street_id')).trim(),
            address1: text(data.get('address1')).trim(),
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
            district: text(address.district).trim(),
            street: text(address.street).trim()
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
        setMessage('', false);
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
        setMessage('', false);
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

    function cardLineFromPayload(payload) {
        return [
            payload.country || payload.country_code || '',
            payload.province || '',
            payload.city || '',
            payload.district || '',
            payload.address1 || payload.street || ''
        ].filter(function (part) {
            return text(part).trim() !== '';
        }).join(' / ');
    }

    function buildCardPayload(address, email) {
        return {
            id: text(address.id || ''),
            name: text(address.contact_name || address.name || ''),
            phone: text(address.contact_phone || address.phone || ''),
            email: text(email || readShippingSnapshot().email || initial.email || ''),
            country_code: text(address.country_code || '').toUpperCase(),
            country: text(address.country || address.country_name || address.country_code || ''),
            province: text(address.province || ''),
            city: text(address.city || ''),
            district: text(address.district || ''),
            address1: text(address.street || address.address1 || ''),
            postal_code: text(address.postal_code || '')
        };
    }

    function updateCardElement(card, payload, selected) {
        if (!card) {
            return;
        }
        card.setAttribute('data-address-json', JSON.stringify(payload));
        card.classList.toggle('is-selected', !!selected);
        var radio = card.querySelector('[data-address-radio]');
        if (radio) {
            radio.checked = !!selected;
            radio.value = payload.id;
        }
        var nameNode = card.querySelector('.w-shipping-checkout-address__card-name');
        if (nameNode) {
            nameNode.textContent = payload.name;
            nameNode.hidden = payload.name === '';
        }
        var metaNode = card.querySelector('.w-shipping-checkout-address__card-meta');
        if (metaNode) {
            metaNode.textContent = payload.phone;
            metaNode.hidden = payload.phone === '';
        }
        var lineNode = card.querySelector('.w-shipping-checkout-address__card-line');
        if (lineNode) {
            lineNode.textContent = cardLineFromPayload(payload);
        }
    }

    function renderSavedAddresses(ctx) {
        var addresses = ctx && Array.isArray(ctx.addresses) ? ctx.addresses : [];
        if (!savedBox || !addresses.length) {
            return;
        }
        var cardsHost = savedBox.querySelector('.w-shipping-checkout-address__cards');
        if (!cardsHost) {
            return;
        }
        var selected = ctx.selected && typeof ctx.selected === 'object' ? ctx.selected : null;
        var selectedId = text(selected && selected.id ? selected.id : root.getAttribute('data-selected-id'));
        var email = text((ctx.checkout_address && ctx.checkout_address.email) || initial.email || '');
        var editLabel = '';
        try {
            editLabel = JSON.parse(root.getAttribute('data-i18n') || '{}').edit || '编辑';
        } catch (e) {
            editLabel = '编辑';
        }
        cardsHost.innerHTML = '';
        addresses.forEach(function (address) {
            var payload = buildCardPayload(address, email);
            var id = payload.id;
            var isSelected = selectedId !== '' ? id === selectedId : !!address.is_selected;
            var card = document.createElement('label');
            card.className = 'w-shipping-checkout-address__card' + (isSelected ? ' is-selected' : '');
            card.setAttribute('data-address-card', '');
            card.setAttribute('data-address-id', id);
            card.innerHTML =
                '<input class="w-shipping-checkout-address__radio" type="radio" name="shipping_address_id" value="' +
                id.replace(/"/g, '') + '" ' + (isSelected ? 'checked' : '') + ' data-address-radio>' +
                '<span class="w-shipping-checkout-address__card-body">' +
                (payload.name ? '<strong class="w-shipping-checkout-address__card-name">' + payload.name + '</strong>' : '') +
                (payload.phone ? '<span class="w-shipping-checkout-address__card-meta">' + payload.phone + '</span>' : '') +
                '<span class="w-shipping-checkout-address__card-line">' + cardLineFromPayload(payload) + '</span>' +
                '</span>' +
                '<button type="button" class="w-shipping-checkout-address__card-edit" data-edit-address data-address-id="' +
                id.replace(/"/g, '') + '">' + editLabel + '</button>';
            updateCardElement(card, payload, isSelected);
            cardsHost.appendChild(card);
        });
        if (selectedId !== '') {
            markSelected(selectedId);
        }
        root.setAttribute('data-has-saved', addresses.length ? '1' : '0');
    }

    function shippingInput(field) {
        var key = text(field).trim();
        if (key === '' || !form) {
            return null;
        }
        var escaped = key.replace(/"/g, '');
        if (editor) {
            var scoped = editor.querySelector('[data-shipping-field][name="' + escaped + '"]');
            if (scoped) {
                return scoped;
            }
        }
        return form.querySelector('[name="' + escaped + '"]');
    }

    function setMessage(textValue, isError) {
        var messageEl = root.querySelector('[data-shipping-message]');
        if (!messageEl) {
            return;
        }
        var next = text(textValue).trim();
        messageEl.textContent = next;
        messageEl.classList.toggle('is-error', !!isError);
        messageEl.hidden = next === '';
    }

    function sanitizeErrorMessage(value) {
        var message = text(value).trim();
        if (message === '') {
            return '';
        }
        var devSuffix = message.match(/\s+in\s+[\/\\].+\.php:\d+$/);
        if (devSuffix) {
            message = message.slice(0, devSuffix.index).trim();
        }
        if (/^[\w\\]+Exception:\s*/.test(message)) {
            message = message.replace(/^[\w\\]+Exception:\s*/, '').trim();
        }
        return message;
    }

    function fieldErrorEl(field) {
        var key = text(field).trim();
        if (key === '') {
            return null;
        }
        var existing = root.querySelector('[data-field-error-for="' + key.replace(/"/g, '') + '"]');
        if (existing) {
            return existing;
        }
        var input = shippingInput(key);
        if (!input) {
            return null;
        }
        var el = document.createElement('span');
        el.className = 'w-shipping-checkout-address__field-error';
        el.setAttribute('data-field-error-for', key);
        el.setAttribute('role', 'alert');
        el.hidden = true;
        var host = input.closest('label') || input.parentElement;
        if (host) {
            host.appendChild(el);
        }
        return el;
    }

    function clearFieldErrorFor(field) {
        var key = text(field).trim();
        if (key === '') {
            return;
        }
        var errEl = root.querySelector('[data-field-error-for="' + key.replace(/"/g, '') + '"]');
        if (errEl) {
            errEl.textContent = '';
            errEl.hidden = true;
        }
        var input = shippingInput(key);
        if (input) {
            input.classList.remove('is-invalid');
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
        }
    }

    function clearFieldErrors() {
        root.querySelectorAll('[data-field-error-for]').forEach(function (el) {
            el.textContent = '';
            el.hidden = true;
        });
        root.querySelectorAll('[data-shipping-field].is-invalid').forEach(function (input) {
            input.classList.remove('is-invalid');
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
        });
    }

    function applyFieldErrors(errors) {
        clearFieldErrors();
        if (!errors || typeof errors !== 'object') {
            return false;
        }
        var applied = false;
        Object.keys(errors).forEach(function (field) {
            var message = sanitizeErrorMessage(errors[field]);
            if (message === '') {
                return;
            }
            applied = true;
            var errEl = fieldErrorEl(field);
            if (errEl) {
                errEl.textContent = message;
                errEl.hidden = false;
            }
            var input = shippingInput(field);
            if (input) {
                input.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
                if (errEl && errEl.id === '') {
                    errEl.id = 'shipping-field-error-' + field.replace(/[^a-z0-9_-]/gi, '');
                }
                if (errEl && errEl.id) {
                    input.setAttribute('aria-describedby', errEl.id);
                }
            }
        });
        if (applied) {
            setMessage('', false);
        }
        return applied;
    }

    function focusFirstFieldError() {
        var firstError = root.querySelector('[data-field-error-for]:not([hidden])');
        if (!firstError) {
            return;
        }
        var field = text(firstError.getAttribute('data-field-error-for')).trim();
        if (editor && editor.hasAttribute('hidden')) {
            setMode(text(root.getAttribute('data-selected-id')) !== '' ? 'edit' : 'new');
            setShippingRequired(true);
        }
        var input = shippingInput(field);
        var target = input || firstError;
        if (target && typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({behavior: 'smooth', block: 'center'});
        }
        if (input && typeof input.focus === 'function') {
            input.focus({preventScroll: true});
        }
    }

    function extractFieldErrors(source) {
        if (!source || typeof source !== 'object') {
            return null;
        }
        var direct = source.field_errors;
        if (direct && typeof direct === 'object' && Object.keys(direct).length) {
            return direct;
        }
        var nested = source.data && source.data.field_errors;
        if (nested && typeof nested === 'object' && Object.keys(nested).length) {
            return nested;
        }
        var responseData = source.response
            && source.response.data
            && source.response.data.data
            && source.response.data.data.field_errors;
        if (responseData && typeof responseData === 'object' && Object.keys(responseData).length) {
            return responseData;
        }
        return null;
    }

    function buildRequiredFieldErrors(snapshot) {
        var snap = snapshot && typeof snapshot === 'object' ? snapshot : readShippingSnapshot();
        var errors = {};
        if (!text(snap.name).trim()) {
            errors.name = t('err_name', '请填写收货人。');
        }
        if (!text(snap.phone).trim()) {
            errors.phone = t('err_phone', '请填写电话。');
        }
        if (!text(snap.address1).trim()) {
            errors.address1 = t('err_address1', '请填写详细地址。');
        }
        return errors;
    }

    function inferFieldErrorsFromMessage(message, snapshot) {
        var normalized = sanitizeErrorMessage(message);
        if (normalized === '') {
            return null;
        }
        if (/收货人|电话|详细地址/.test(normalized)) {
            var required = buildRequiredFieldErrors(snapshot);
            if (Object.keys(required).length) {
                return required;
            }
        }
        return null;
    }

    function validateShippingFields(snapshot) {
        var errors = buildRequiredFieldErrors(snapshot);
        if (!Object.keys(errors).length) {
            clearFieldErrors();
            return true;
        }
        applyFieldErrors(errors);
        focusFirstFieldError();
        return false;
    }

    function handleAddressSaveFailure(errorOrResult, fallbackMessage) {
        var fieldErrors = extractFieldErrors(errorOrResult);
        if (!fieldErrors) {
            fieldErrors = inferFieldErrorsFromMessage(
                text(errorOrResult && (errorOrResult.message || errorOrResult.msg)),
                readShippingSnapshot()
            );
        }
        if (fieldErrors && applyFieldErrors(fieldErrors)) {
            focusFirstFieldError();
            return true;
        }
        var message = sanitizeErrorMessage(
            text(errorOrResult && (errorOrResult.message || errorOrResult.msg))
        ) || text(fallbackMessage);
        setMessage(message, true);
        return false;
    }

    function appendCaptchaFields(payload, scope) {
        if (!scope) {
            return;
        }
        scope.querySelectorAll('[name^="captcha_"]').forEach(function (input) {
            if (!input.name) {
                return;
            }
            payload[input.name] = text(input.value).trim();
        });
    }

    async function ensureLazyCaptcha() {
        var host = root.querySelector('[data-weline-captcha-lazy]');
        if (!host || host.getAttribute('data-loaded') === '1') {
            return;
        }
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('api_missing');
        }
        var api = await window.Weline.Api.resource('checkout');
        if (!api || typeof api.getDeliveryCaptchaChallenge !== 'function') {
            return;
        }
        var result = await api.getDeliveryCaptchaChallenge({
            intent: host.getAttribute('data-intent') || 'checkout.save_delivery_address',
            form_id: host.getAttribute('data-form-id') || 'checkout-shipping-address-editor'
        }, {silent: true});
        var challenge = result && (result.data || result) || {};
        var html = text(challenge.html);
        if (!html) {
            return;
        }
        host.outerHTML = html;
        if (window.Weline && window.Weline.Form && typeof window.Weline.Form.mount === 'function') {
            window.Weline.Form.mount(root);
        }
    }

    function applyDeliveryContext(ctx) {
        if (!ctx || typeof ctx !== 'object') {
            return;
        }
        var checkoutAddress = ctx.checkout_address && typeof ctx.checkout_address === 'object'
            ? ctx.checkout_address
            : null;
        if (checkoutAddress) {
            fillShipping(Object.assign({}, checkoutAddress, {
                district: checkoutAddress.district || (ctx.selected && ctx.selected.district) || ''
            }));
        }
        renderSavedAddresses(ctx);
        if (ctx.selected && ctx.selected.id) {
            markSelected(ctx.selected.id);
        }
        window.dispatchEvent(new CustomEvent('weline:checkout:address-updated', {
            detail: ctx,
        }));
    }

    async function commitEditedAddress() {
        var snap = readShippingSnapshot();
        if (!validateShippingFields(snap)) {
            return;
        }
        var selectedId = text(root.getAttribute('data-selected-id'));
        var isNew = mode() === 'new' || selectedId === '';
        var payload = {
            address: {
                id: isNew ? '' : selectedId,
                contact_name: snap.name,
                contact_phone: snap.phone,
                country_code: snap.country_code || 'CN',
                country: snap.country || snap.country_code || 'CN',
                province: snap.province,
                city: snap.city,
                district: snap.district,
                street: snap.address1,
                postal_code: snap.postal_code
            }
        };
        var useBtn = root.querySelector('[data-use-edited-address]');
        if (text(root.getAttribute('data-is-logged-in')) !== '1') {
            try {
                await ensureLazyCaptcha();
                appendCaptchaFields(payload, root);
            } catch (e) {
                setMessage(e.message || '验证码加载失败，请稍后重试。', true);
                return;
            }
        }
        if (useBtn) {
            useBtn.disabled = true;
        }
        setMessage('', false);
        clearFieldErrors();
        try {
            if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                throw new Error('Weline.Api 尚未就绪，请刷新页面后重试。');
            }
            var api = await window.Weline.Api.resource('checkout');
            if (!api || typeof api.saveDeliveryAddress !== 'function') {
                throw new Error('地址保存接口不可用。');
            }
            var result = await api.saveDeliveryAddress(payload, {silent: true, keepBusinessResult: true});
            if (!result || result.success === false) {
                if (handleAddressSaveFailure(result, '地址保存失败，请稍后重试。')) {
                    return;
                }
                throw new Error(sanitizeErrorMessage(text(result && result.message)) || '地址保存失败，请稍后重试。');
            }
            var ctx = result.data || result;
            applyDeliveryContext(ctx);
            setMode('collapsed');
            setShippingRequired(false);
            clearFieldErrors();
            setMessage('地址已更新。', false);
        } catch (error) {
            if (!handleAddressSaveFailure(error, '地址保存失败，请稍后重试。')) {
                setMessage(sanitizeErrorMessage(error.message) || '地址保存失败，请稍后重试。', true);
            }
        } finally {
            if (useBtn) {
                useBtn.disabled = false;
            }
        }
    }

    function useEditedAddress() {
        commitEditedAddress();
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
    root.addEventListener('input', function (event) {
        var target = event.target;
        if (target && target.matches && target.matches('[data-shipping-field][name]')) {
            clearFieldErrorFor(target.name);
        }
        if (target && target.matches && target.matches('[data-shipping-field][name="postal_code"]')) {
            schedulePostalLookup(target.value);
        }
    });
    var postalLookupTimer = null;
    var postalLookupToken = 0;
    function schedulePostalLookup(rawPostal) {
        window.clearTimeout(postalLookupTimer);
        postalLookupTimer = window.setTimeout(function () {
            runPostalLookup(rawPostal);
        }, 350);
    }
    function runPostalLookup(rawPostal) {
        var postal = text(rawPostal).trim();
        if (postal.length < 3) {
            return;
        }
        if (!window.WelineThemeAddress || typeof window.WelineThemeAddress.postalLookup !== 'function') {
            return;
        }
        var snap = readShippingSnapshot();
        var countryCode = snap.country_code || 'CN';
        var token = ++postalLookupToken;
        window.WelineThemeAddress.postalLookup(countryCode, postal, 8).then(function (rows) {
            if (token !== postalLookupToken) {
                return;
            }
            if (!Array.isArray(rows) || !rows.length) {
                return;
            }
            var best = rows[0];
            if (!best || best.needs_cascade_confirm) {
                // still apply best-effort ids when present
            }
            if (typeof window.WelineThemeAddress.applyPostalCandidate === 'function') {
                return window.WelineThemeAddress.applyPostalCandidate(ADDRESS_CODE, Object.assign({
                    country_code: countryCode
                }, best));
            }
            return null;
        }).catch(function () {
            return null;
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

    root.setAttribute('data-shipping-js-rev', '20260903-csa6');
    window.WelineShippingCheckoutAddress = {
        rev: '20260903-csa6',
        root: root,
        applyFieldErrors: applyFieldErrors,
        focusFirstFieldError: focusFirstFieldError,
        sanitizeErrorMessage: sanitizeErrorMessage,
        validateShippingFields: validateShippingFields,
        buildRequiredFieldErrors: buildRequiredFieldErrors,
    };
})();
