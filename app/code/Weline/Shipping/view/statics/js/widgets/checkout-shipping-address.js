(function () {
    'use strict';

    function mount(root) {
    if (!root || !root.querySelector) {
        return null;
    }
    if (root.getAttribute('data-shipping-mounted') === '1') {
        var existingApi = window.WelineShippingCheckoutAddress;
        if (existingApi && existingApi.root === root && typeof existingApi.syncChangeAddressLabel === 'function') {
            existingApi.syncChangeAddressLabel(root.getAttribute('data-mode') || 'collapsed');
            return existingApi;
        }
        // Fallback: isolation modal may set data-session-isolation after first mount.
        var btn = root.querySelector('[data-change-address]');
        var isolated = root.getAttribute('data-session-isolation') === '1'
            || !!(root.closest && root.closest('[data-session-isolation="1"]'));
        var hasSaved = root.getAttribute('data-has-saved') === '1'
            || root.querySelectorAll('[data-address-card]').length > 0;
        var picking = (root.getAttribute('data-mode') || '') === 'picking';
        var multi = (parseInt(root.getAttribute('data-address-count') || '0', 10) || 0) > 1
            || root.querySelectorAll('[data-address-card]').length > 1;
        if (btn && (picking || multi || (isolated && hasSaved))) {
            btn.hidden = false;
            if (!picking) {
                btn.textContent = (function () {
                    try {
                        var i18n = JSON.parse(root.getAttribute('data-i18n') || '{}') || {};
                        return i18n.change_address || '更换地址';
                    } catch (e) {
                        return '更换地址';
                    }
                })();
            }
        }
        return existingApi || null;
    }
    root.setAttribute('data-shipping-mounted', '1');

    var ADDRESS_CODE = 'checkout-shipping-address';
    var BILLING_ADDRESS_CODE = 'checkout-billing-address';
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
        syncChangeAddressLabel(next);
        if (!editor) {
            return;
        }
        if (next === 'collapsed' || next === 'picking') {
            editor.setAttribute('hidden', 'hidden');
            setShippingRequired(false);
        } else {
            editor.removeAttribute('hidden');
            setShippingRequired(true);
            refreshGuestCaptchaOnOpen();
        }
    }

    function syncChangeAddressLabel(next) {
        var btn = root.querySelector('[data-change-address]');
        if (!btn) {
            return;
        }
        var picking = next === 'picking';
        var hasSaved = text(root.getAttribute('data-has-saved')) === '1'
            || root.querySelectorAll('[data-address-card]').length > 0;
        // 有已存地址即可「更换地址」进入 picking 并拉全量列表（SSR 常只渲染已选卡）。
        var showChange = picking || hasSaved;
        btn.hidden = !showChange;
        btn.textContent = picking
            ? t('done_change', '收起地址列表')
            : t('change_address', '更换地址');
        btn.setAttribute('aria-expanded', picking ? 'true' : 'false');
    }

    function ensureSavedShell() {
        savedBox = root.querySelector('[data-saved-addresses]');
        if (savedBox) {
            savedBox.hidden = false;
            return savedBox;
        }
        var section = root.querySelector('[data-shipping-section]');
        if (!section || !editor) {
            return null;
        }
        var box = document.createElement('div');
        box.className = 'w-shipping-checkout-address__saved';
        box.setAttribute('data-saved-addresses', '');
        box.innerHTML =
            '<div class="w-shipping-checkout-address__saved-toolbar">' +
            '<p class="w-shipping-checkout-address__saved-label">' + t('saved_heading', '选择收货地址') + '</p>' +
            '<button type="button" class="w-shipping-checkout-address__link-btn" data-change-address aria-expanded="false" hidden>' +
            t('change_address', '更换地址') + '</button></div>' +
            '<div class="w-shipping-checkout-address__cards" role="radiogroup" aria-label="' +
            t('saved_heading', '选择收货地址') + '"></div>' +
            '<button type="button" class="w-shipping-checkout-address__link-btn" data-add-address">' +
            t('add_new', '使用新地址') + '</button>';
        section.insertBefore(box, editor);
        savedBox = box;
        return box;
    }

    function collapseAddressList() {
        var selectedId = text(root.getAttribute('data-selected-id'));
        if (selectedId !== '') {
            markSelected(selectedId);
        }
        setMode('collapsed');
    }

    function isAddressPayloadComplete(address) {
        if (!address || typeof address !== 'object') {
            return false;
        }
        var name = text(address.name || address.contact_name).trim();
        var phone = text(address.phone || address.contact_phone).trim();
        var line = text(address.address1 || address.street).trim();
        var country = text(address.country_code || address.country).trim();
        return name !== '' && phone !== '' && line !== '' && country !== '';
    }

    /**
     * 续付：订单地址齐全则收起为地址卡；不全才展开编辑器。
     * @param {object} address
     * @param {{expand?: boolean}} opts
     */
    function presentOrderAddress(address, opts) {
        var forceExpand = !!(opts && opts.expand);
        var payload = address && typeof address === 'object' ? address : {};
        var complete = isAddressPayloadComplete(payload);
        var localId = text(payload.id || payload.address_id || '').trim() || 'cpay-order';
        var localPayload = {
            id: localId,
            name: text(payload.name || payload.contact_name).trim(),
            phone: text(payload.phone || payload.contact_phone).trim(),
            email: text(payload.email).trim(),
            country_code: text(payload.country_code || payload.country).trim(),
            country: text(payload.country || payload.country_name || payload.country_code).trim(),
            province: text(payload.province).trim(),
            city: text(payload.city).trim(),
            district: text(payload.district).trim(),
            street: text(payload.address1 || payload.street).trim(),
            address1: text(payload.address1 || payload.street).trim(),
            postal_code: text(payload.postal_code).trim(),
            display_label: text(payload.display_label || payload.full_address).trim(),
            is_selected: true,
        };
        if (!localPayload.display_label) {
            localPayload.display_label = [localPayload.name, localPayload.phone, localPayload.address1]
                .filter(function (p) { return p !== ''; })
                .join(' · ');
        }
        fillShipping(localPayload);
        if (forceExpand || !complete) {
            ensureSavedShell();
            setMode(text(root.getAttribute('data-selected-id')) !== '' ? 'edit' : 'new');
            setShippingRequired(true);
            root.setAttribute('data-has-saved', root.getAttribute('data-has-saved') === '1' ? '1' : '0');
            return { complete: false, mode: mode() };
        }
        ensureSavedShell();
        upsertLocalSavedAddress(localPayload);
        if (savedBox) {
            savedBox.hidden = false;
        }
        setMode('collapsed');
        setShippingRequired(false);
        root.setAttribute('data-has-saved', '1');
        syncChangeAddressLabel('collapsed');
        return { complete: true, mode: 'collapsed' };
    }

    function collectAddressesFromCards() {
        var out = [];
        root.querySelectorAll('[data-address-card]').forEach(function (card) {
            var payload = {};
            try {
                payload = JSON.parse(card.getAttribute('data-address-json') || '{}') || {};
            } catch (e) {
                payload = {};
            }
            var id = text(payload.id || card.getAttribute('data-address-id'));
            if (id === '') {
                return;
            }
            payload.id = id;
            payload.is_selected = card.classList.contains('is-selected');
            out.push(payload);
        });
        return out;
    }

    function upsertLocalSavedAddress(localPayload) {
        var id = text(localPayload && localPayload.id);
        if (id === '') {
            return;
        }
        var merged = collectAddressesFromCards();
        var found = false;
        merged.forEach(function (row) {
            if (text(row.id) === id) {
                Object.keys(localPayload).forEach(function (key) {
                    row[key] = localPayload[key];
                });
                row.is_selected = true;
                found = true;
            } else {
                row.is_selected = false;
            }
        });
        if (!found) {
            merged.push(Object.assign({}, localPayload, {is_selected: true}));
        }
        renderSavedAddresses({
            addresses: merged,
            selected: localPayload,
            checkout_address: localPayload,
        });
        // 本地 upsert 后允许再点「更换地址」从服务端刷新完整地址簿（不污染结账会话）。
        root.setAttribute('data-list-loaded', '0');
        syncChangeAddressLabel(mode());
    }

    async function openAddressPicker() {
        setMode('picking');
        var btn = root.querySelector('[data-change-address]');
        if (btn) {
            btn.disabled = true;
        }
        try {
            if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                root.setAttribute('data-list-loaded', '1');
                return;
            }
            var api = await window.Weline.Api.resource('checkout');
            if (!api || typeof api.getDeliveryContext !== 'function') {
                root.setAttribute('data-list-loaded', '1');
                return;
            }
            // 每次进入 picking 都拉全量地址簿（含其它国家），避免仅当前配送国 1 条时误以为「没有其它地址」。
            var result = await api.getDeliveryContext({ list_all_addresses: true, address_purpose: 'checkout' }, {silent: true});
            var ctx = (result && (result.data || result)) || {};
            if (Array.isArray(ctx.addresses) && ctx.addresses.length) {
                // 隔离弹窗：合并本地未入库卡，避免「更换地址」刷新冲掉本单刚编辑的地址。
                if (isSessionIsolated()) {
                    var serverIds = {};
                    ctx.addresses.forEach(function (row) {
                        serverIds[text(row.id || row.delivery_address_id || '')] = true;
                    });
                    collectAddressesFromCards().forEach(function (local) {
                        var lid = text(local.id);
                        if (lid !== '' && !serverIds[lid] && lid.indexOf('local_') === 0) {
                            ctx.addresses.push(Object.assign({}, local, {is_selected: false}));
                        }
                    });
                }
                renderSavedAddresses(ctx);
            }
            root.setAttribute('data-list-loaded', '1');
        } catch (e) {
            setMessage(t('load_addresses_failed', '地址列表加载失败，请稍后重试。'), true);
        } finally {
            if (btn) {
                btn.disabled = false;
            }
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
        if (isPhoneFieldName(name) || (input.matches && input.matches('[data-phone-field]'))) {
            next = sanitizePhoneInput(next);
        }
        if (input.value === next) {
            return;
        }
        input.value = next;
        input.dispatchEvent(new Event('change', {bubbles: true}));
    }

    /** 全球电话：数字 + 可选前导 +，分隔符空格 - () . /；字母一律剔除 */
    function sanitizePhoneInput(value) {
        return text(value).replace(/[^0-9+\-\s().\/]/g, '');
    }

    function isPhoneFieldName(name) {
        var key = text(name).trim();
        return key === 'phone' || key === 'billing_phone' || key === 'contact_phone';
    }

    /** 与后端 AddressValidationService::isValidInternationalPhone 对齐（E.164 位数 7–15） */
    function isValidPhone(value) {
        var phone = text(value).trim();
        if (phone === '' || phone.length > 32) {
            return false;
        }
        if (!/^\+?[0-9\-\s().\/]+$/.test(phone)) {
            return false;
        }
        if (phone.indexOf('+') > 0) {
            return false;
        }
        if ((phone.match(/\+/g) || []).length > 1) {
            return false;
        }
        if (!/^\+?[0-9]/.test(phone)) {
            return false;
        }
        var digits = phone.replace(/\D/g, '');
        return digits.length >= 7 && digits.length <= 15;
    }

    function applyPhoneFieldSanitize(input) {
        if (!input) {
            return false;
        }
        var raw = String(input.value || '');
        var next = sanitizePhoneInput(raw);
        if (raw === next) {
            return false;
        }
        var start = typeof input.selectionStart === 'number' ? input.selectionStart : next.length;
        var removed = 0;
        var i = 0;
        var j = 0;
        while (i < start && i < raw.length) {
            if (j < next.length && raw.charAt(i) === next.charAt(j)) {
                j += 1;
            } else {
                removed += 1;
            }
            i += 1;
        }
        input.value = next;
        var pos = Math.max(0, start - removed);
        try {
            input.setSelectionRange(pos, pos);
        } catch (_e) {}
        return true;
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
            province_code: text(data.get('province_code')).trim(),
            province_region_id: text(data.get('province_region_id')).trim(),
            city: text(data.get('city')).trim(),
            city_code: text(data.get('city_code')).trim(),
            city_region_id: text(data.get('city_region_id')).trim(),
            district: text(data.get('district')).trim(),
            district_code: text(data.get('district_code')).trim(),
            district_region_id: text(data.get('district_region_id')).trim(),
            street: text(data.get('street')).trim(),
            street_id: text(data.get('street_id')).trim(),
            street_code: text(data.get('street_code')).trim(),
            address1: text(data.get('address1')).trim(),
            postal_code: text(data.get('postal_code')).trim(),
            address_id: text(data.get('shipping_address_id') || data.get('address_id')).trim(),
            shipping_address_id: text(data.get('shipping_address_id') || data.get('address_id')).trim()
        };
    }

    function selectedCardPayload() {
        var card = root.querySelector('[data-address-card].is-selected');
        if (!card) {
            var radio = root.querySelector('[data-address-radio]:checked');
            card = radio && radio.closest ? radio.closest('[data-address-card]') : null;
        }
        if (!card) {
            return null;
        }
        try {
            var payload = JSON.parse(card.getAttribute('data-address-json') || '{}') || {};
            return payload && typeof payload === 'object' ? payload : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Quote/reload must use the selected saved card — not cascade defaults (often CN)
     * left in the hidden editor after currency full-page navigation.
     */
    function resolveQuoteAddress() {
        var currentMode = mode();
        var formSnap = readShippingSnapshot();
        if (currentMode === 'new' || currentMode === 'edit') {
            return formSnap;
        }
        var payload = selectedCardPayload();
        if (!payload || text(payload.country_code).trim() === '') {
            return formSnap;
        }
        var address1 = text(payload.address1 || payload.street || '').trim();
        var id = text(payload.id || payload.delivery_address_id || payload.address_id || '').trim();
        return {
            name: text(payload.name || payload.contact_name || formSnap.name || '').trim(),
            phone: text(payload.phone || payload.contact_phone || formSnap.phone || '').trim(),
            email: text(payload.email || formSnap.email || '').trim(),
            country_code: text(payload.country_code).trim().toUpperCase(),
            country: text(payload.country || payload.country_name || payload.country_code || '').trim(),
            province: text(payload.province || '').trim(),
            province_code: text(payload.province_code || '').trim(),
            province_region_id: text(payload.province_region_id || payload.province_id || '0').trim(),
            city: text(payload.city || '').trim(),
            city_code: text(payload.city_code || '').trim(),
            city_region_id: text(payload.city_region_id || payload.city_id || '0').trim(),
            district: text(payload.district || '').trim(),
            district_code: text(payload.district_code || '').trim(),
            district_region_id: text(payload.district_region_id || payload.district_id || '0').trim(),
            street: address1,
            street_id: text(payload.street_id || '0').trim(),
            street_code: text(payload.street_code || '').trim(),
            address1: address1,
            postal_code: text(payload.postal_code || '').trim(),
            address_id: id,
            shipping_address_id: id
        };
    }

    function syncFormFromSelectedCard() {
        var payload = selectedCardPayload();
        if (!payload || text(payload.country_code).trim() === '') {
            return false;
        }
        fillShipping({
            name: payload.name || payload.contact_name || '',
            phone: payload.phone || payload.contact_phone || '',
            email: payload.email || '',
            country_code: payload.country_code,
            country: payload.country || payload.country_name || payload.country_code || '',
            province: payload.province || '',
            city: payload.city || '',
            district: payload.district || '',
            street: payload.address1 || payload.street || '',
            address1: payload.address1 || payload.street || '',
            postal_code: payload.postal_code || ''
        }, {clearEmpty: true});
        return true;
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

    function isSessionIsolated() {
        if (text(root.getAttribute('data-session-isolation')) === '1') {
            return true;
        }
        return !!(root.closest && root.closest('[data-session-isolation="1"]'));
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
        // Quick-buy / HelpPay modal: local selection only — do not rewrite universal checkout delivery.
        if (isSessionIsolated()) {
            return;
        }
        if (!id || !window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return;
        }
        try {
            var api = await window.Weline.Api.resource('checkout');
            if (!api || typeof api.selectDeliveryAddress !== 'function') {
                return;
            }
            var result = await api.selectDeliveryAddress({id: id, address_purpose: 'checkout'}, {silent: true});
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
        resetAlsoUseReceivingDefault();
        setMode('edit');
        setMessage('', false);
        if (editor && typeof editor.scrollIntoView === 'function') {
            editor.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }
    }

    function resetAlsoUseReceivingDefault() {
        var box = root.querySelector('[data-also-use-receiving]');
        if (box) {
            box.checked = true;
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
        resetAlsoUseReceivingDefault();
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
            id: text(address.id || address.delivery_address_id || address.address_id || ''),
            name: text(address.contact_name || address.name || ''),
            phone: text(address.contact_phone || address.phone || ''),
            email: text(email || readShippingSnapshot().email || initial.email || ''),
            country_code: text(address.country_code || '').toUpperCase(),
            country: text(address.country || address.country_name || address.country_code || ''),
            province: text(address.province || ''),
            province_region_id: Number(address.province_region_id || address.province_id || 0) || 0,
            city: text(address.city || ''),
            city_region_id: Number(address.city_region_id || address.city_id || 0) || 0,
            district: text(address.district || ''),
            district_region_id: Number(address.district_region_id || address.district_id || 0) || 0,
            address1: text(address.street || address.address1 || ''),
            postal_code: text(address.postal_code || ''),
            embargo_blocked: !!(address.embargo_blocked || address.destination_blocked),
            embargo_message: text(address.embargo_message || address.destination_block_message || '')
        };
    }

    function applyEmbargoOnCard(card, payload) {
        if (!card) {
            return;
        }
        var blocked = !!(payload && payload.embargo_blocked);
        var message = text(
            (payload && payload.embargo_message)
            || t('embargo_hint', '该地址所在地区暂不支持配送（禁运）。')
        );
        card.classList.toggle('is-embargoed', blocked);
        card.setAttribute('data-embargo-blocked', blocked ? '1' : '0');
        var hint = card.querySelector('[data-address-embargo]');
        if (!hint) {
            var body = card.querySelector('.w-shipping-checkout-address__card-body');
            if (body) {
                hint = document.createElement('span');
                hint.className = 'w-shipping-checkout-address__card-embargo';
                hint.setAttribute('data-address-embargo', '');
                hint.setAttribute('role', 'status');
                body.appendChild(hint);
            }
        }
        if (hint) {
            hint.textContent = blocked ? message : '';
            hint.hidden = !blocked;
        }
    }

    function updateCardElement(card, payload, selected) {
        if (!card) {
            return;
        }
        card.setAttribute('data-address-json', JSON.stringify(payload));
        card.classList.toggle('is-selected', !!selected);
        applyEmbargoOnCard(card, payload);
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

    function synthesizeAddressFromContext(ctx) {
        var selected = ctx && ctx.selected && typeof ctx.selected === 'object' ? ctx.selected : null;
        if (selected && text(selected.id || selected.delivery_address_id || '')) {
            return selected;
        }
        var formAddr = ctx && ctx.checkout_address && typeof ctx.checkout_address === 'object'
            ? ctx.checkout_address
            : null;
        if (!formAddr) {
            return null;
        }
        var id = text(formAddr.address_id || formAddr.id || root.getAttribute('data-selected-id'));
        var name = text(formAddr.name || formAddr.contact_name || '');
        var phone = text(formAddr.phone || formAddr.contact_phone || '');
        var line = text(formAddr.address1 || formAddr.street || '');
        if (id === '' && name === '' && phone === '' && line === '') {
            return null;
        }
        if (id === '') {
            id = 'local_' + Date.now().toString(36);
        }
        return {
            id: id,
            contact_name: name,
            contact_phone: phone,
            country_code: formAddr.country_code || '',
            country: formAddr.country || formAddr.country_name || '',
            province: formAddr.province || '',
            city: formAddr.city || '',
            district: formAddr.district || '',
            street: formAddr.address1 || formAddr.street || '',
            postal_code: formAddr.postal_code || '',
            is_selected: true
        };
    }

    function renderSavedAddresses(ctx) {
        var addresses = ctx && Array.isArray(ctx.addresses) ? ctx.addresses.slice() : [];
        if (!addresses.length) {
            var fallback = synthesizeAddressFromContext(ctx);
            if (fallback) {
                addresses = [fallback];
            }
        }
        if (!addresses.length) {
            return;
        }
        if (!ensureSavedShell()) {
            return;
        }
        var cardsHost = savedBox.querySelector('.w-shipping-checkout-address__cards');
        if (!cardsHost) {
            return;
        }
        var selected = ctx && ctx.selected && typeof ctx.selected === 'object' ? ctx.selected : null;
        var selectedId = text(
            (selected && (selected.id || selected.delivery_address_id))
            || (ctx && ctx.checkout_address && ctx.checkout_address.address_id)
            || root.getAttribute('data-selected-id')
        );
        var email = text((ctx && ctx.checkout_address && ctx.checkout_address.email) || initial.email || '');
        var editLabel = t('edit', '编辑');
        var nextCards = [];
        addresses.forEach(function (address) {
            var payload = buildCardPayload(address, email);
            var id = payload.id || text(address.delivery_address_id || '');
            if (id === '') {
                return;
            }
            payload.id = id;
            nextCards.push({payload: payload, is_selected: !!address.is_selected});
        });
        if (!nextCards.length) {
            return;
        }
        if (selectedId === '') {
            selectedId = nextCards[0].payload.id;
        }
        cardsHost.innerHTML = '';
        nextCards.forEach(function (entry) {
            var payload = entry.payload;
            var id = payload.id;
            var isSelected = id === selectedId || (!selectedId && entry.is_selected);
            var card = document.createElement('label');
            card.className = 'w-shipping-checkout-address__card'
                + (isSelected ? ' is-selected' : '')
                + (payload.embargo_blocked ? ' is-embargoed' : '');
            card.setAttribute('data-address-card', '');
            card.setAttribute('data-address-id', id);
            card.setAttribute('data-embargo-blocked', payload.embargo_blocked ? '1' : '0');
            card.innerHTML =
                '<input class="w-shipping-checkout-address__radio" type="radio" name="shipping_address_id" value="' +
                id.replace(/"/g, '') + '" ' + (isSelected ? 'checked' : '') + ' data-address-radio>' +
                '<span class="w-shipping-checkout-address__card-body">' +
                '<strong class="w-shipping-checkout-address__card-name"' + (payload.name ? '' : ' hidden') + '>' +
                (payload.name || '') + '</strong>' +
                '<span class="w-shipping-checkout-address__card-meta"' + (payload.phone ? '' : ' hidden') + '>' +
                (payload.phone || '') + '</span>' +
                '<span class="w-shipping-checkout-address__card-line">' + cardLineFromPayload(payload) + '</span>' +
                '<span class="w-shipping-checkout-address__card-embargo" data-address-embargo role="status" hidden></span>' +
                '</span>' +
                '<button type="button" class="w-shipping-checkout-address__card-edit" data-edit-address data-address-id="' +
                id.replace(/"/g, '') + '">' + editLabel + '</button>';
            updateCardElement(card, payload, isSelected);
            cardsHost.appendChild(card);
        });
        markSelected(selectedId);
        if (!root.querySelector('[data-address-card].is-selected')) {
            var firstCard = cardsHost.querySelector('[data-address-card]');
            if (firstCard) {
                markSelected(text(firstCard.getAttribute('data-address-id')));
            }
        }
        var count = cardsHost.querySelectorAll('[data-address-card]').length;
        root.setAttribute('data-has-saved', count ? '1' : '0');
        root.setAttribute('data-address-count', String(count));
        root.setAttribute('data-list-loaded', count <= 1 ? '1' : text(root.getAttribute('data-list-loaded') || '0'));
        syncChangeAddressLabel(mode());
    }

    function shippingInput(field) {
        var key = text(field).trim();
        if (key === '' || !form) {
            return null;
        }
        var escaped = key.replace(/"/g, '');
        if (key.indexOf('billing_') === 0 && billingEditor) {
            var billingScoped = billingEditor.querySelector('[name="' + escaped + '"]');
            if (billingScoped) {
                return billingScoped;
            }
        }
        if (editor) {
            var scoped = editor.querySelector('[data-shipping-field][name="' + escaped + '"]');
            if (scoped) {
                return scoped;
            }
        }
        return form.querySelector('[name="' + escaped + '"]');
    }

    function setMessage(textValue, isError) {
        var messageEl = root.querySelector('.w-shipping-checkout-address__editor-action-row [data-shipping-message]')
            || root.querySelector('[data-shipping-message]');
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
        root.querySelectorAll('[data-shipping-field].is-invalid, [data-billing-field].is-invalid').forEach(function (input) {
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
        var phone = text(snap.phone).trim();
        if (!phone) {
            errors.phone = t('err_phone', '请填写电话。');
        } else if (!isValidPhone(phone)) {
            errors.phone = t('err_phone_invalid', '电话号码格式不正确');
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

    function selectedEmbargoCard() {
        var selectedId = text(root.getAttribute('data-selected-id'));
        var card = selectedId
            ? root.querySelector('[data-address-card][data-address-id="' + selectedId.replace(/"/g, '') + '"]')
            : root.querySelector('[data-address-card].is-selected');
        if (!card || card.getAttribute('data-embargo-blocked') !== '1') {
            return null;
        }
        return card;
    }

    function selectedEmbargoMessage(card) {
        var hint = card && card.querySelector('[data-address-embargo]');
        var fromHint = text(hint && hint.textContent).trim();
        if (fromHint !== '' && !hint.hidden) {
            return fromHint;
        }
        try {
            var payload = JSON.parse(card.getAttribute('data-address-json') || '{}') || {};
            var fromPayload = text(payload.embargo_message || '').trim();
            if (fromPayload !== '') {
                return fromPayload;
            }
        } catch (_e) {}
        return t('embargo_hint', '该地址所在地区暂不支持配送（禁运）。');
    }

    async function validateShippingFields(snapshot, options) {
        var opts = options && typeof options === 'object' ? options : {};
        var embargoCard = selectedEmbargoCard();
        if (embargoCard) {
            var embargoMsg = selectedEmbargoMessage(embargoCard);
            setMessage(embargoMsg, true);
            if (typeof embargoCard.scrollIntoView === 'function') {
                embargoCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            return false;
        }
        var errors = opts.skipRequired === true ? {} : buildRequiredFieldErrors(snapshot);
        if (window.WelineThemeAddress && typeof window.WelineThemeAddress.evaluateEmbargo === 'function') {
            try {
                var embargo = await window.WelineThemeAddress.evaluateEmbargo({
                    country_code: snapshot.country_code || '',
                    province_code: snapshot.province_code || snapshot.province || '',
                    province_region_id: snapshot.province_region_id || 0,
                    city_code: snapshot.city_code || snapshot.city || '',
                    city_region_id: snapshot.city_region_id || 0,
                    district_code: snapshot.district_code || snapshot.district || '',
                    district_region_id: snapshot.district_region_id || 0,
                    street_code: snapshot.street_code || snapshot.street || '',
                    street_id: snapshot.street_id || 0
                });
                if (embargo && embargo.blocked) {
                    var level = text(embargo.level || 'country');
                    var field = level === 'country' ? 'country_code'
                        : (level === 'street' ? 'address1' : level);
                    errors[field] = text(embargo.message) || 'Delivery not supported';
                }
            } catch (_e) {
                // keep required-field validation
            }
        }
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
        if (/验证码|captcha/i.test(message)) {
            refreshGuestCaptchaOnOpen();
        }
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

    function captchaBinder() {
        return document.getElementById('checkout-shipping-address-editor')
            || root.querySelector('[data-shipping-editor], [data-address-editor]')
            || root;
    }

    function readCaptchaProvider() {
        var el = root.querySelector('[data-weline-captcha-provider]');
        return el ? text(el.getAttribute('data-weline-captcha-provider')).trim() : '';
    }

    function readCaptchaResponse() {
        var el = root.querySelector('[data-weline-captcha-provider]');
        var scope = el || root;
        var input = scope.querySelector('[name="captcha_response"]');
        return input ? text(input.value).trim() : '';
    }

    function bindCaptchaLifecycle() {
        if (root.dataset.captchaLifeBound === '1') {
            return;
        }
        root.dataset.captchaLifeBound = '1';
        root.addEventListener('weline:captcha:degrade', function (event) {
            var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
            if (text(detail.prefer) !== 'local_image') {
                return;
            }
            if (typeof event.stopPropagation === 'function') {
                event.stopPropagation();
            }
            ensureLazyCaptcha(true, 'local_image').then(function () {
                setMessage(t('captcha_degraded', '云端人机验证暂不可用，已切换为本地图码，请填写后重试。'), true);
            }).catch(function () {
                setMessage(t('captcha_degrade_failed', '人机验证加载失败，请稍后重试。'), true);
            });
        });
    }

    async function refreshGuestCaptchaOnOpen() {
        if (text(root.getAttribute('data-is-logged-in')) === '1') {
            return;
        }
        var target = root.querySelector('[data-weline-captcha-lazy], [data-weline-captcha-provider], .weline-captcha');
        if (!target) {
            return;
        }
        bindCaptchaLifecycle();
        try {
            if (window.Weline && window.Weline.Captcha && typeof window.Weline.Captcha.refresh === 'function') {
                await window.Weline.Captcha.refresh(target);
                return;
            }
        } catch (_e) {
            // fall through to checkout challenge API
        }
        try {
            await ensureLazyCaptcha(true);
        } catch (_e2) {
            // 打开编辑器时换码失败不阻断表单；提交时再报错
        }
    }

    async function ensureLazyCaptcha(force, prefer) {
        var host = root.querySelector('[data-weline-captcha-lazy]');
        var existing = root.querySelector('[data-weline-captcha-provider], .weline-captcha');
        if (!force && host && host.getAttribute('data-loaded') === '1') {
            return;
        }
        if (!force && !host && existing && !prefer) {
            return;
        }
        if (window.Weline && window.Weline.Captcha && typeof window.Weline.Captcha.refresh === 'function' && (host || existing)) {
            if (prefer === 'local_image' && typeof window.Weline.Captcha.degradeToLocal === 'function') {
                await window.Weline.Captcha.degradeToLocal(host || existing);
                return;
            }
            await window.Weline.Captcha.refresh(host || existing, prefer || undefined);
            return;
        }
        if (!host && !existing) {
            return;
        }
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('api_missing');
        }
        var api = await window.Weline.Api.resource('checkout');
        if (!api || typeof api.getDeliveryCaptchaChallenge !== 'function') {
            return;
        }
        var anchor = host || existing;
        var challengeParams = {
            intent: (host && host.getAttribute('data-intent')) || 'checkout.save_delivery_address',
            form_id: (host && host.getAttribute('data-form-id')) || 'checkout-shipping-address-editor'
        };
        if (prefer === 'local_image') {
            challengeParams.prefer = 'local_image';
        }
        var result = await api.getDeliveryCaptchaChallenge(challengeParams, {silent: true});
        var challenge = result && (result.data || result) || {};
        var html = text(challenge.html);
        if (!html || !anchor) {
            return;
        }
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        var node = wrap.querySelector('.weline-captcha, [data-weline-captcha-provider]') || wrap.firstElementChild;
        if (!node) {
            return;
        }
        // Keep scripts that providers append after the captcha root.
        var scripts = [];
        Array.prototype.forEach.call(wrap.querySelectorAll('script'), function (script) {
            scripts.push(script);
        });
        anchor.replaceWith(node);
        scripts.forEach(function (oldScript) {
            if (oldScript.parentNode === wrap || !document.contains(oldScript)) {
                var s = document.createElement('script');
                if (oldScript.src) {
                    s.src = oldScript.src;
                    s.async = oldScript.async;
                    s.defer = oldScript.defer;
                } else {
                    s.textContent = oldScript.textContent || '';
                }
                (node.parentNode || root).appendChild(s);
            }
        });
        bindCaptchaLifecycle();
        if (window.Weline && window.Weline.Form && typeof window.Weline.Form.mount === 'function') {
            window.Weline.Form.mount(root);
        }
    }

    /**
     * Google/腾讯 token 由 prepare-submit 异步写入；地址保存走 API，不能空票直发。
     * SDK 失败会 degrade → 本地图码，本次保存中止并提示用户填写。
     */
    async function ensureCaptchaTokenBeforeSave() {
        bindCaptchaLifecycle();
        await ensureLazyCaptcha();
        var provider = readCaptchaProvider();
        if (!provider) {
            return;
        }
        if (provider === 'local_image') {
            if (readCaptchaResponse() === '') {
                throw new Error(t('captcha_required', '请填写验证码'));
            }
            return;
        }
        if (provider !== 'google_enterprise' && provider !== 'tencent_captcha') {
            return;
        }
        if (readCaptchaResponse() !== '') {
            return;
        }
        var binder = captchaBinder();
        if (text(binder.dataset.welineCaptchaPending) === '1') {
            throw new Error(t('captcha_pending', '人机验证尚未就绪，请稍后重试'));
        }
        await new Promise(function (resolve, reject) {
            var settled = false;
            var timer = window.setTimeout(function () {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                reject(new Error(t('captcha_pending', '人机验证尚未就绪，请稍后重试')));
            }, 12000);
            function cleanup() {
                binder.removeEventListener('weline:form:verified', onVerified);
                binder.removeEventListener('weline:captcha:degrade', onDegrade);
                window.clearTimeout(timer);
            }
            function onVerified() {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                resolve();
            }
            function onDegrade() {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                reject(new Error(t('captcha_degraded', '云端人机验证暂不可用，已切换为本地图码，请填写后重试。')));
            }
            binder.addEventListener('weline:form:verified', onVerified);
            binder.addEventListener('weline:captcha:degrade', onDegrade);
            binder.dispatchEvent(new CustomEvent('weline:form:prepare-submit', {
                bubbles: true,
                cancelable: true,
                detail: {
                    form: binder,
                    intent: 'checkout.save_delivery_address'
                }
            }));
            if (readCaptchaResponse() !== '') {
                settled = true;
                cleanup();
                resolve();
            }
        });
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
        if (isSessionIsolated()) {
            return;
        }
        window.dispatchEvent(new CustomEvent('weline:checkout:address-updated', {
            detail: ctx,
        }));
    }

    async function commitEditedAddress() {
        var snap = readShippingSnapshot();
        if (!(await validateShippingFields(snap))) {
            return;
        }
        var selectedId = text(root.getAttribute('data-selected-id'));
        var isNew = mode() === 'new' || selectedId === '';
        var alsoReceiving = root.querySelector('[data-also-use-receiving]');
        var payload = {
            purpose_source: 'checkout',
            also_use_receiving: alsoReceiving ? (alsoReceiving.checked ? 1 : 0) : 1,
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
                postal_code: snap.postal_code,
                purpose_source: 'checkout',
                also_use_receiving: alsoReceiving ? (alsoReceiving.checked ? 1 : 0) : 1
            }
        };
        var useBtn = root.querySelector('[data-use-edited-address]');
        if (text(root.getAttribute('data-is-logged-in')) !== '1') {
            try {
                await ensureCaptchaTokenBeforeSave();
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
        // Isolated modal: apply local card state only — never saveDeliveryAddress into checkout session.
        if (isSessionIsolated()) {
            var localId = selectedId || ('local_' + Date.now().toString(36));
            var localPayload = {
                id: localId,
                name: snap.name,
                phone: snap.phone,
                email: snap.email || '',
                country_code: snap.country_code || '',
                country: snap.country || '',
                province: snap.province || '',
                city: snap.city || '',
                district: snap.district || '',
                street: snap.address1 || snap.street || '',
                address1: snap.address1 || snap.street || '',
                postal_code: snap.postal_code || '',
            };
            markSelected(localId);
            fillShipping(localPayload);
            // Upsert into existing cards — do not wipe the book (that hid「更换地址」).
            upsertLocalSavedAddress(Object.assign({}, localPayload, {is_selected: true}));
            if (savedBox) {
                savedBox.hidden = false;
            }
            setMode('collapsed');
            setShippingRequired(false);
            setMessage(t('address_updated_local', '地址已用于本单（不影响结账页）。'), false);
            if (useBtn) {
                useBtn.disabled = false;
            }
            return;
        }
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
            if (!root.querySelector('[data-address-card].is-selected')) {
                renderSavedAddresses(ctx);
            }
            if (savedBox) {
                savedBox.hidden = false;
            }
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
        billingEditor.querySelectorAll('input, select, textarea').forEach(function (input) {
            input.disabled = !on;
            if (on) {
                input.removeAttribute('disabled');
            } else {
                input.setAttribute('disabled', 'disabled');
            }
        });
        billingEditor.querySelectorAll('button').forEach(function (btn) {
            btn.disabled = !on;
        });
        if (on && window.WelineThemeAddress && typeof window.WelineThemeAddress.boot === 'function') {
            window.WelineThemeAddress.boot();
        }
    }

    function billingSavedBox() {
        return root.querySelector('[data-billing-saved]');
    }

    function billingCardsHost() {
        return root.querySelector('[data-billing-cards]');
    }

    function billingMode() {
        return text(root.getAttribute('data-billing-mode') || 'same');
    }

    function setBillingMessage(message, isError) {
        var el = root.querySelector('[data-billing-message]');
        if (!el) {
            return;
        }
        var textMsg = text(message).trim();
        if (!textMsg) {
            el.hidden = true;
            el.textContent = '';
            el.removeAttribute('data-tone');
            return;
        }
        el.hidden = false;
        el.textContent = textMsg;
        el.setAttribute('data-tone', isError ? 'danger' : 'success');
    }

    function syncBillingChangeLabel(next) {
        var btn = root.querySelector('[data-change-billing-address]');
        if (!btn) {
            return;
        }
        var count = billingCardsHost()
            ? billingCardsHost().querySelectorAll('[data-billing-card]').length
            : 0;
        var picking = next === 'picking';
        var show = picking || count > 1 || text(root.getAttribute('data-billing-list-loaded')) !== '1';
        btn.hidden = !show && count <= 1;
        btn.setAttribute('aria-expanded', picking ? 'true' : 'false');
        btn.textContent = picking
            ? t('done_change', '收起地址列表')
            : t('change_address', '更换地址');
    }

    function setBillingMode(next) {
        root.setAttribute('data-billing-mode', next);
        var saved = billingSavedBox();
        var cancelBtn = root.querySelector('[data-cancel-billing-edit]');
        if (!billingEditor) {
            return;
        }
        if (next === 'same') {
            billingEditor.setAttribute('hidden', 'hidden');
            if (saved) {
                saved.hidden = true;
            }
            if (cancelBtn) {
                cancelBtn.hidden = true;
            }
            syncBillingChangeLabel(next);
            return;
        }
        if (saved) {
            saved.hidden = false;
        }
        if (next === 'collapsed' || next === 'picking') {
            billingEditor.setAttribute('hidden', 'hidden');
            if (cancelBtn) {
                cancelBtn.hidden = true;
            }
        } else {
            billingEditor.removeAttribute('hidden');
            if (cancelBtn) {
                var hasCards = !!(billingCardsHost() && billingCardsHost().querySelector('[data-billing-card]'));
                cancelBtn.hidden = !hasCards;
            }
        }
        syncBillingChangeLabel(next);
    }

    function readBillingSnapshot() {
        if (!form) {
            return {};
        }
        var data = new FormData(form);
        return {
            id: text(data.get('billing_address_id') || root.getAttribute('data-billing-selected-id')).trim(),
            name: text(data.get('billing_name')).trim(),
            phone: text(data.get('billing_phone')).trim(),
            email: text(data.get('billing_email')).trim(),
            country_code: text(data.get('billing_country_code')).trim().toUpperCase() || 'CN',
            country: text(data.get('billing_country')).trim(),
            province: text(data.get('billing_province')).trim(),
            province_code: text(data.get('billing_province_code')).trim(),
            province_region_id: text(data.get('billing_province_region_id')).trim(),
            city: text(data.get('billing_city')).trim(),
            city_code: text(data.get('billing_city_code')).trim(),
            city_region_id: text(data.get('billing_city_region_id')).trim(),
            district: text(data.get('billing_district')).trim(),
            district_code: text(data.get('billing_district_code')).trim(),
            district_region_id: text(data.get('billing_district_region_id')).trim(),
            street: text(data.get('billing_street')).trim(),
            address1: text(data.get('billing_address1')).trim(),
            postal_code: text(data.get('billing_postal_code')).trim()
        };
    }

    function applyBillingCascade(address) {
        if (!window.WelineThemeAddress || typeof window.WelineThemeAddress.applyValues !== 'function') {
            return Promise.resolve(false);
        }
        return window.WelineThemeAddress.applyValues(BILLING_ADDRESS_CODE, {
            country_code: text(address.country_code).trim().toUpperCase(),
            country: text(address.country || address.country_name || address.country_code).trim(),
            province: text(address.province || '').trim(),
            city: text(address.city || '').trim(),
            district: text(address.district || '').trim(),
            street: text(address.street || '').trim()
        });
    }

    function fillBilling(address, options) {
        options = options || {};
        address = address || {};
        var clearEmpty = !!options.clearEmpty;
        function put(name, value) {
            if (!clearEmpty && text(value).trim() === '') {
                return;
            }
            setField(name, value, billingEditor);
        }
        put('billing_name', address.name || address.contact_name || '');
        put('billing_phone', address.phone || address.contact_phone || '');
        put('billing_email', address.email || '');
        put('billing_postal_code', address.postal_code || '');
        put('billing_address1', address.address1 || address.street || '');
        if (address.id || address.delivery_address_id) {
            root.setAttribute('data-billing-selected-id', text(address.id || address.delivery_address_id));
            var idInput = form && form.querySelector('[name="billing_address_id"]');
            if (!idInput && billingEditor) {
                idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'billing_address_id';
                idInput.setAttribute('data-billing-field', '');
                billingEditor.appendChild(idInput);
            }
            if (idInput) {
                idInput.value = text(address.id || address.delivery_address_id);
            }
        }
        applyBillingCascade(address);
    }

    function markBillingSelected(id) {
        root.setAttribute('data-billing-selected-id', text(id));
        var host = billingCardsHost();
        if (!host) {
            return;
        }
        host.querySelectorAll('[data-billing-card]').forEach(function (card) {
            var on = text(card.getAttribute('data-address-id')) === text(id);
            card.classList.toggle('is-selected', on);
            var radio = card.querySelector('[data-billing-radio]');
            if (radio) {
                radio.checked = on;
            }
        });
    }

    function collectBillingAddressesFromCards() {
        var out = [];
        var host = billingCardsHost();
        if (!host) {
            return out;
        }
        host.querySelectorAll('[data-billing-card]').forEach(function (card) {
            var payload = {};
            try {
                payload = JSON.parse(card.getAttribute('data-address-json') || '{}') || {};
            } catch (e) {
                payload = {};
            }
            var id = text(payload.id || card.getAttribute('data-address-id'));
            if (id === '') {
                return;
            }
            payload.id = id;
            payload.is_selected = card.classList.contains('is-selected');
            out.push(payload);
        });
        return out;
    }

    function renderBillingSavedAddresses(ctx) {
        var host = billingCardsHost();
        var saved = billingSavedBox();
        if (!host || !saved) {
            return;
        }
        var addresses = ctx && Array.isArray(ctx.addresses) ? ctx.addresses.slice() : [];
        if (!addresses.length) {
            return;
        }
        var selectedId = text(
            root.getAttribute('data-billing-selected-id')
            || (ctx.selected && (ctx.selected.id || ctx.selected.delivery_address_id))
            || ''
        );
        var email = text((ctx.checkout_address && ctx.checkout_address.email) || initial.email || '');
        var editLabel = t('edit', '编辑');
        host.textContent = '';
        addresses.forEach(function (address) {
            var payload = buildCardPayload(address, email);
            var id = payload.id;
            if (id === '') {
                return;
            }
            if (selectedId === '') {
                selectedId = id;
            }
            var isSelected = id === selectedId;
            var card = document.createElement('label');
            card.className = 'w-shipping-checkout-address__card' + (isSelected ? ' is-selected' : '');
            card.setAttribute('data-billing-card', '');
            card.setAttribute('data-address-id', id);
            card.setAttribute('data-address-json', JSON.stringify(payload));

            var radio = document.createElement('input');
            radio.className = 'w-shipping-checkout-address__radio';
            radio.type = 'radio';
            radio.name = 'billing_address_pick';
            radio.value = id;
            radio.checked = isSelected;
            radio.setAttribute('data-billing-radio', '');
            card.appendChild(radio);

            var body = document.createElement('span');
            body.className = 'w-shipping-checkout-address__card-body';
            var nameEl = document.createElement('strong');
            nameEl.className = 'w-shipping-checkout-address__card-name';
            nameEl.textContent = payload.name;
            nameEl.hidden = payload.name === '';
            body.appendChild(nameEl);
            var metaEl = document.createElement('span');
            metaEl.className = 'w-shipping-checkout-address__card-meta';
            metaEl.textContent = payload.phone;
            metaEl.hidden = payload.phone === '';
            body.appendChild(metaEl);
            var lineEl = document.createElement('span');
            lineEl.className = 'w-shipping-checkout-address__card-line';
            lineEl.textContent = cardLineFromPayload(payload);
            body.appendChild(lineEl);
            card.appendChild(body);

            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'w-shipping-checkout-address__card-edit';
            editBtn.setAttribute('data-edit-billing-address', '');
            editBtn.setAttribute('data-address-id', id);
            editBtn.textContent = editLabel;
            card.appendChild(editBtn);

            host.appendChild(card);
        });
        if (selectedId) {
            markBillingSelected(selectedId);
        }
        var count = host.querySelectorAll('[data-billing-card]').length;
        root.setAttribute('data-billing-has-saved', count ? '1' : '0');
        root.setAttribute('data-billing-address-count', String(count));
        saved.hidden = count === 0;
        syncBillingChangeLabel(billingMode());
    }

    function selectBillingSaved(card) {
        if (!card) {
            return;
        }
        var id = text(card.getAttribute('data-address-id'));
        var payload = {};
        try {
            payload = JSON.parse(card.getAttribute('data-address-json') || '{}') || {};
        } catch (e) {
            payload = {};
        }
        // 账单选址仅写本区字段，禁止调用 selectDeliveryAddress（那会改收货配送会话）。
        markBillingSelected(id);
        fillBilling(payload, {clearEmpty: true});
        setBillingMode('collapsed');
        setBillingMessage('', false);
    }

    function openBillingEdit(card) {
        if (card) {
            selectBillingSaved(card);
        }
        setBillingEnabled(true);
        setBillingMode('edit');
        setBillingMessage('', false);
        if (billingEditor && typeof billingEditor.scrollIntoView === 'function') {
            billingEditor.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }
    }

    function openBillingNew() {
        var host = billingCardsHost();
        if (host) {
            host.querySelectorAll('[data-billing-radio]').forEach(function (radio) {
                radio.checked = false;
            });
            host.querySelectorAll('[data-billing-card]').forEach(function (card) {
                card.classList.remove('is-selected');
            });
        }
        root.setAttribute('data-billing-selected-id', '');
        fillBilling({
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
        setBillingEnabled(true);
        setBillingMode('new');
        setBillingMessage('', false);
        if (billingEditor && typeof billingEditor.scrollIntoView === 'function') {
            billingEditor.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }
    }

    function cancelBillingEdit() {
        var selectedId = text(root.getAttribute('data-billing-selected-id'));
        var host = billingCardsHost();
        var card = selectedId && host
            ? host.querySelector('[data-billing-card][data-address-id="' + selectedId.replace(/"/g, '') + '"]')
            : (host && host.querySelector('[data-billing-card].is-selected'));
        if (card) {
            selectBillingSaved(card);
            return;
        }
        var first = host && host.querySelector('[data-billing-card]');
        if (first) {
            selectBillingSaved(first);
            return;
        }
        setBillingMode('new');
    }

    function collapseBillingList() {
        var selectedId = text(root.getAttribute('data-billing-selected-id'));
        if (selectedId !== '') {
            markBillingSelected(selectedId);
        }
        setBillingMode('collapsed');
    }

    async function openBillingPicker() {
        setBillingMode('picking');
        var btn = root.querySelector('[data-change-billing-address]');
        if (btn) {
            btn.disabled = true;
        }
        try {
            if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                root.setAttribute('data-billing-list-loaded', '1');
                return;
            }
            var api = await window.Weline.Api.resource('checkout');
            if (!api || typeof api.getDeliveryContext !== 'function') {
                root.setAttribute('data-billing-list-loaded', '1');
                return;
            }
            // 只读拉地址簿给账单挑选；不 selectDeliveryAddress，不碰收货会话。
            var result = await api.getDeliveryContext({ list_all_addresses: true, address_purpose: 'checkout' }, {silent: true});
            var ctx = (result && (result.data || result)) || {};
            if (Array.isArray(ctx.addresses) && ctx.addresses.length) {
                var serverIds = {};
                ctx.addresses.forEach(function (row) {
                    serverIds[text(row.id || row.delivery_address_id || '')] = true;
                });
                collectBillingAddressesFromCards().forEach(function (local) {
                    var lid = text(local.id);
                    if (lid !== '' && !serverIds[lid] && lid.indexOf('billing_local_') === 0) {
                        ctx.addresses.push(Object.assign({}, local, {is_selected: false}));
                    }
                });
                renderBillingSavedAddresses(ctx);
            }
            root.setAttribute('data-billing-list-loaded', '1');
            syncBillingChangeLabel('picking');
        } catch (e) {
            setBillingMessage(t('load_addresses_failed', '地址列表加载失败，请稍后重试。'), true);
        } finally {
            if (btn) {
                btn.disabled = false;
            }
        }
    }

    function validateBillingFields(snap) {
        var address = snap || readBillingSnapshot();
        var errors = {};
        if (!text(address.name).trim()) {
            errors.billing_name = t('err_name', '请填写收货人。');
        }
        var phone = text(address.phone).trim();
        if (!phone) {
            errors.billing_phone = t('err_phone', '请填写电话。');
        } else if (!isValidPhone(phone)) {
            errors.billing_phone = t('err_phone_invalid', '电话号码格式不正确');
        }
        if (!text(address.address1).trim()) {
            errors.billing_address1 = t('err_address1', '请填写详细地址。');
        }
        if (Object.keys(errors).length) {
            if (applyFieldErrors(errors)) {
                focusFirstFieldError();
            } else {
                var firstKey = Object.keys(errors)[0];
                setBillingMessage(errors[firstKey], true);
            }
            return false;
        }
        clearFieldErrorFor('billing_phone');
        setBillingMessage('', false);
        return true;
    }

    function commitBillingAddress() {
        var snap = readBillingSnapshot();
        if (!validateBillingFields(snap)) {
            return;
        }
        var id = text(snap.id || root.getAttribute('data-billing-selected-id'));
        if (id === '' || billingMode() === 'new') {
            id = 'billing_local_' + Date.now().toString(36);
        }
        snap.id = id;
        var merged = collectBillingAddressesFromCards();
        var found = false;
        merged.forEach(function (row) {
            if (text(row.id) === id) {
                Object.keys(snap).forEach(function (key) {
                    row[key] = snap[key];
                });
                row.is_selected = true;
                found = true;
            } else {
                row.is_selected = false;
            }
        });
        if (!found) {
            merged.push(Object.assign({}, snap, {is_selected: true}));
        }
        renderBillingSavedAddresses({
            addresses: merged,
            selected: snap,
            checkout_address: snap
        });
        markBillingSelected(id);
        fillBilling(snap, {clearEmpty: true});
        setBillingMode('collapsed');
        setBillingMessage(t('billing_saved', '账单地址已保存。'), false);
        root.setAttribute('data-billing-list-loaded', '0');
        syncBillingChangeLabel('collapsed');
    }

    async function activateBillingBook() {
        setBillingEnabled(true);
        setBillingMessage('', false);
        var host = billingCardsHost();
        var hasCards = !!(host && host.querySelector('[data-billing-card]'));
        if (hasCards) {
            var selected = host.querySelector('[data-billing-card].is-selected')
                || host.querySelector('[data-billing-card]');
            if (selected) {
                selectBillingSaved(selected);
            } else {
                setBillingMode('collapsed');
            }
            return;
        }
        // 首次展开：只读拉地址簿；有则选卡收起，无则空白编辑。
        try {
            if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
                var api = await window.Weline.Api.resource('checkout');
                if (api && typeof api.getDeliveryContext === 'function') {
                    var result = await api.getDeliveryContext({ list_all_addresses: true, address_purpose: 'checkout' }, {silent: true});
                    var ctx = (result && (result.data || result)) || {};
                    if (Array.isArray(ctx.addresses) && ctx.addresses.length) {
                        renderBillingSavedAddresses(ctx);
                        var first = billingCardsHost() && billingCardsHost().querySelector('[data-billing-card]');
                        if (first) {
                            selectBillingSaved(first);
                            root.setAttribute('data-billing-list-loaded', ctx.addresses.length <= 1 ? '1' : '0');
                            return;
                        }
                    }
                }
            }
        } catch (e) {
            // fall through to blank editor
        }
        fillBilling({
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
        setBillingMode('new');
    }

    function onBillingSameChange() {
        var box = root.querySelector('[data-billing-same]');
        var same = !box || box.checked;
        if (same) {
            setBillingEnabled(false);
            setBillingMode('same');
            setBillingMessage('', false);
            return;
        }
        activateBillingBook();
    }

    function bootAddress() {
        if (window.WelineThemeAddress && typeof window.WelineThemeAddress.boot === 'function') {
            window.WelineThemeAddress.boot();
        }
        var seed = selectedCardPayload() || initial;
        return Promise.resolve(applyCascade(seed)).then(function () {
            // Collapsed saved-card mode: keep editor fields aligned with the visible card
            // so currency reload / getData never quotes the cascade default (CN).
            if (mode() === 'collapsed' || mode() === 'picking') {
                syncFormFromSelectedCard();
            }
            return true;
        });
    }

    // 事件委托到 root：访客首存后动态显示的 saved shell 也能选中/编辑/新增/更换
    root.addEventListener('change', function (event) {
        var radio = event.target && event.target.closest ? event.target.closest('[data-address-radio]') : null;
        var box = root.querySelector('[data-saved-addresses]');
        if (!radio || !box || !box.contains(radio)) {
            return;
        }
        var card = radio.closest('[data-address-card]');
        if (card) {
            selectSaved(card);
        }
    });
    root.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target && target.closest)) {
            return;
        }
        var editBtn = target.closest('[data-edit-address]');
        var box = root.querySelector('[data-saved-addresses]');
        if (editBtn && box && box.contains(editBtn)) {
            event.preventDefault();
            event.stopPropagation();
            openEdit(editBtn.closest('[data-address-card]'));
            return;
        }
        var add = target.closest('[data-add-address]');
        if (add && root.contains(add)) {
            event.preventDefault();
            openNew();
            return;
        }
        var change = target.closest('[data-change-address]');
        if (change && root.contains(change)) {
            event.preventDefault();
            if (mode() === 'picking') {
                collapseAddressList();
                return;
            }
            openAddressPicker();
        }
    });
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
    function onAddressFieldEvent(event) {
        var target = event.target;
        if (!(target && target.matches)) {
            return;
        }
        if (target.matches('[data-phone-field], [name="phone"], [name="billing_phone"]')) {
            applyPhoneFieldSanitize(target);
        }
        if (target.matches('[data-shipping-field][name], [data-billing-field][name]')) {
            clearFieldErrorFor(target.name);
        }
        // Postal→cascade lookup: <w:theme:address postal-lookup> / address.js
    }
    root.addEventListener('input', onAddressFieldEvent);
    root.addEventListener('change', onAddressFieldEvent);
    root.addEventListener('beforeinput', function (event) {
        var target = event.target;
        if (!(target && target.matches && target.matches('[data-phone-field], [name="phone"], [name="billing_phone"]'))) {
            return;
        }
        if (typeof event.data === 'string' && event.data !== '' && /[^0-9+\-\s().\/]/.test(event.data)) {
            event.preventDefault();
        }
    });

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
    root.addEventListener('change', function (event) {
        var radio = event.target && event.target.closest ? event.target.closest('[data-billing-radio]') : null;
        var box = billingSavedBox();
        if (!radio || !box || !box.contains(radio)) {
            return;
        }
        var card = radio.closest('[data-billing-card]');
        if (card) {
            selectBillingSaved(card);
        }
    });
    root.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target && target.closest)) {
            return;
        }
        var editBilling = target.closest('[data-edit-billing-address]');
        var billingBox = billingSavedBox();
        if (editBilling && billingBox && billingBox.contains(editBilling)) {
            event.preventDefault();
            event.stopPropagation();
            openBillingEdit(editBilling.closest('[data-billing-card]'));
            return;
        }
        var addBilling = target.closest('[data-add-billing-address]');
        if (addBilling && root.contains(addBilling)) {
            event.preventDefault();
            openBillingNew();
            return;
        }
        var changeBilling = target.closest('[data-change-billing-address]');
        if (changeBilling && root.contains(changeBilling)) {
            event.preventDefault();
            if (billingMode() === 'picking') {
                collapseBillingList();
                return;
            }
            openBillingPicker();
            return;
        }
        var cancelBilling = target.closest('[data-cancel-billing-edit]');
        if (cancelBilling && root.contains(cancelBilling)) {
            event.preventDefault();
            cancelBillingEdit();
            return;
        }
        var useBilling = target.closest('[data-use-billing-address]');
        if (useBilling && root.contains(useBilling)) {
            event.preventDefault();
            commitBillingAddress();
        }
    });

    setBillingEnabled(false);
    setBillingMode('same');
    root.setAttribute('data-billing-list-loaded', '0');
    root.setAttribute('data-billing-has-saved', '0');
    root.setAttribute('data-billing-selected-id', '');
    if (text(root.getAttribute('data-has-saved')) === '1') {
        var addressCount = parseInt(root.getAttribute('data-address-count') || '0', 10) || 0;
        if (addressCount <= 1) {
            root.setAttribute('data-list-loaded', '1');
        }
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

    root.setAttribute('data-shipping-js-rev', '20260921-cpay-addr1');
    var api = {
        rev: '20260921-cpay-addr1',
        root: root,
        mount: mount,
        applyFieldErrors: applyFieldErrors,
        focusFirstFieldError: focusFirstFieldError,
        sanitizeErrorMessage: sanitizeErrorMessage,
        validateShippingFields: validateShippingFields,
        buildRequiredFieldErrors: buildRequiredFieldErrors,
        openAddressPicker: openAddressPicker,
        collapseAddressList: collapseAddressList,
        presentOrderAddress: presentOrderAddress,
        resolveQuoteAddress: resolveQuoteAddress,
        syncFormFromSelectedCard: syncFormFromSelectedCard,
        syncChangeAddressLabel: syncChangeAddressLabel,
    };
    window.WelineShippingCheckoutAddress = api;
    return api;
    }

    window.WelineShippingCheckoutAddress = {
        rev: '20260921-cpay-addr1',
        root: null,
        mount: mount,
        applyFieldErrors: function () {},
        focusFirstFieldError: function () {},
        sanitizeErrorMessage: function (m) { return m == null ? '' : String(m); },
        validateShippingFields: function () { return true; },
        buildRequiredFieldErrors: function () { return {}; },
        openAddressPicker: function () {},
        collapseAddressList: function () {},
        presentOrderAddress: function () { return { complete: false, mode: 'new' }; },
        resolveQuoteAddress: function () { return null; },
        syncFormFromSelectedCard: function () { return false; },
        syncChangeAddressLabel: function () {},
    };

    var existing = document.querySelector('[data-shipping-checkout-address]');
    if (existing) {
        mount(existing);
    }
})();
