(function () {
    'use strict';

    var labels = {
        country: '\u4e2d\u56fd',
        contact: '\u8054\u7cfb\u4eba',
        deliveryContact: '\u6536\u8d27\u4eba',
        postal: '\u90ae\u7f16\uff1a',
        defaultText: '\u9ed8\u8ba4',
        edit: '\u7f16\u8f91',
        add: '\u65b0\u589e',
        deliveryAddress: '\u6536\u8d27\u5730\u5740',
        shippingAddress: '\u53d1\u8d27\u5730\u5740',
        setDefault: '\u8bbe\u4e3a\u9ed8\u8ba4',
        remove: '\u5220\u9664',
        settingFailed: '\u8bbe\u7f6e\u5931\u8d25',
        settingSuccess: '\u8bbe\u7f6e\u6210\u529f',
        deleteFailed: '\u5220\u9664\u5931\u8d25',
        deleteSuccess: '\u5220\u9664\u6210\u529f',
        saveFailed: '\u4fdd\u5b58\u5931\u8d25',
        saveSuccess: '\u4fdd\u5b58\u6210\u529f',
        saving: '\u4fdd\u5b58\u4e2d...',
        requestFailed: '\u8bf7\u6c42\u5931\u8d25\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5'
    };
    var apiResources = Object.create(null);

    function text(value) {
        return value == null ? '' : String(value);
    }

    function fullAddress(data) {
        return [data.country, data.province, data.city, data.district, data.street].map(text).filter(Boolean).join(' / ');
    }

    function normalize(data, panel) {
        var idField = panel.getAttribute('data-id-field') || 'id';
        data = data || {};
        data.id = data.id || data[idField] || data.shipping_address_id || data.delivery_address_id || '';
        data[idField] = data[idField] || data.id;
        data.country = data.country || labels.country;
        data.full_address = data.full_address || fullAddress(data);
        data.is_default = data.is_default ? 1 : 0;
        return data;
    }

    function showMessage(panel, message, type) {
        var box = panel.querySelector('[data-address-message]');
        if (!box) {
            return;
        }
        box.textContent = message || '';
        box.classList.remove('account-address-message--success', 'account-address-message--danger');
        box.classList.add(type === 'success' ? 'account-address-message--success' : 'account-address-message--danger');
        box.hidden = !message;
    }

    function escapeHtml(value) {
        return text(value).replace(/[&<>"']/g, function (ch) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[ch];
        });
    }

    function renderAddressParts(data) {
        var icons = {
            country: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><path d="M3.8 12h16.4M12 3.5c2.2 2.3 3.3 5.2 3.3 8.5S14.2 18.2 12 20.5M12 3.5C9.8 5.8 8.7 8.7 8.7 12s1.1 6.2 3.3 8.5"></path></svg>',
            region: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 5.5 9 3.8l6 2.2 4.5-1.7v14.2L15 20.2 9 18l-4.5 1.7V5.5Z"></path><path d="M9 3.8V18M15 6v14.2"></path></svg>',
            city: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 20V8.5h5V20M9.5 20V4h5v16M14.5 20v-9h5v9"></path><path d="M6.5 11h1M6.5 14h1M11.5 7h1M11.5 10h1M11.5 13h1M16.5 14h1"></path></svg>',
            district: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s6.5-5.7 6.5-11A6.5 6.5 0 0 0 5.5 10c0 5.3 6.5 11 6.5 11Z"></path><circle cx="12" cy="10" r="2.3"></circle></svg>',
            street: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11.5 12 5l8 6.5"></path><path d="M6.5 10.5V20h11v-9.5"></path><path d="M10 20v-5h4v5"></path></svg>',
            postal: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6.5h14v11H5z"></path><path d="m5.5 7 6.5 5 6.5-5"></path></svg>'
        };
        var parts = Array.isArray(data.address_tokens) && data.address_tokens.length ? data.address_tokens : [
            {icon: 'country', label: '国家/地区', value: data.country},
            {icon: 'region', label: '省/州', value: data.province},
            {icon: 'city', label: '城市', value: data.city},
            {icon: 'district', label: '区县', value: data.district},
            {icon: 'street', label: '详细地址', value: data.street}
        ];

        return parts.filter(function (part) {
            return text(part.value) !== '';
        }).map(function (part) {
            return '<span class="account-address-part" title="' + escapeHtml(part.label) + '" data-address-part="' + escapeHtml(part.label) + '">'
                + '<span class="account-address-part__icon">' + (icons[part.icon] || icons.street) + '</span>'
                + '<span class="account-address-part__text">' + escapeHtml(part.value) + '</span>'
                + '</span>';
        }).join('');
    }

    function setCardData(card, panel, data) {
        data = normalize(data, panel);
        card.dataset.addressId = data.id;
        card.querySelector('[data-address-name]').textContent = text(data.name);
        card.querySelector('[data-address-contact]').textContent = text(data.contact_name);
        card.querySelector('[data-address-phone]').textContent = text(data.contact_phone);
        var full = card.querySelector('[data-address-full]');
        if (full) {
            full.setAttribute('aria-label', data.full_address || fullAddress(data));
            full.innerHTML = renderAddressParts(data);
        }
        card.querySelector('[data-address-contact-label]').textContent = panel.dataset.addressPanel === 'delivery' ? labels.deliveryContact : labels.contact;

        var postal = card.querySelector('[data-address-postal]');
        postal.textContent = data.postal_code ? labels.postal + data.postal_code : '';
        postal.hidden = !data.postal_code;

        var isDefault = !!data.is_default;
        card.classList.toggle('account-address-card--default', isDefault);
        card.querySelector('[data-address-default-badge]').hidden = !isDefault;
        card.querySelector('[data-address-default]').hidden = isDefault;
        card.querySelector('[data-address-edit]').dataset.addressJson = JSON.stringify(data);
        card.querySelector('[data-address-default]').dataset.id = data.id;
        card.querySelector('[data-address-delete]').dataset.id = data.id;
    }

    function createCard() {
        var card = document.createElement('article');
        card.className = 'account-address-card';
        card.setAttribute('data-address-card', '');
        card.innerHTML = [
            '<header class="account-address-card__header">',
            '<div><strong data-address-name></strong><p><span data-address-contact></span> &middot; <span data-address-phone></span></p></div>',
            '<span class="account-address-card__badge" data-address-default-badge hidden>' + labels.defaultText + '</span>',
            '</header>',
            '<div class="account-address-card__body">',
            '<span class="account-address-card__term" data-address-contact-label></span>',
            '<span class="account-address-card__value" data-address-full></span>',
            '<span class="account-address-card__postal" data-address-postal hidden></span>',
            '</div>',
            '<div class="account-address-card__actions">',
            '<button type="button" class="account-address-card__action" data-address-edit>' + labels.edit + '</button>',
            '<button type="button" class="account-address-card__action" data-address-default>' + labels.setDefault + '</button>',
            '<button type="button" class="account-address-card__action account-address-card__action--danger" data-address-delete>' + labels.remove + '</button>',
            '</div>'
        ].join('');
        return card;
    }

    function upsertCard(panel, data) {
        data = normalize(data, panel);
        var list = panel.querySelector('[data-address-list]');
        var card = list.querySelector('[data-address-id="' + data.id + '"]');
        if (!card) {
            card = createCard();
            list.prepend(card);
        }
        setCardData(card, panel, data);
        panel.querySelector('[data-address-empty]').hidden = !!list.querySelector('[data-address-card]');

        if (data.is_default) {
            list.querySelectorAll('[data-address-card]').forEach(function (item) {
                if (item === card) {
                    return;
                }
                item.classList.remove('account-address-card--default');
                item.querySelector('[data-address-default-badge]').hidden = true;
                item.querySelector('[data-address-default]').hidden = false;
                var edit = item.querySelector('[data-address-edit]');
                try {
                    var itemData = JSON.parse(edit.dataset.addressJson || '{}');
                    itemData.is_default = 0;
                    edit.dataset.addressJson = JSON.stringify(itemData);
                } catch (error) {}
            });
        }
    }

    function openForm(panel, data) {
        var wrap = panel.querySelector('[data-address-form-wrap]');
        var form = panel.querySelector('[data-address-form]');
        data = normalize(data || {}, panel);
        form.reset();
        form.querySelector('[name="' + panel.getAttribute('data-id-field') + '"]').value = data.id || '';
        ['name', 'contact_name', 'contact_phone', 'country', 'country_code', 'province', 'province_code', 'province_region_id', 'city', 'city_code', 'city_region_id', 'district', 'district_code', 'district_region_id', 'street', 'postal_code'].forEach(function (field) {
            var input = form.querySelector('[name="' + field + '"]');
            if (input) {
                input.value = data[field] || (field === 'country' ? labels.country : '');
            }
        });
        var checkbox = form.querySelector('[name="is_default"]');
        if (checkbox) {
            checkbox.checked = !!data.is_default;
        }
        form.querySelector('[data-address-form-title]').textContent = (data.id ? labels.edit : labels.add) + (panel.dataset.addressPanel === 'delivery' ? labels.deliveryAddress : labels.shippingAddress);
        wrap.hidden = false;
        wrap.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    function closeForm(panel) {
        var wrap = panel.querySelector('[data-address-form-wrap]');
        var form = panel.querySelector('[data-address-form]');
        form.reset();
        wrap.hidden = true;
    }

    function formDataToObject(formData) {
        var payload = {};
        formData.forEach(function (value, key) {
            payload[key] = value;
        });
        return payload;
    }

    function getAddressApi(panel) {
        var provider = panel.dataset.addressPanel === 'shipping' ? 'shippingAddress' : 'deliveryAddress';
        if (!apiResources[provider]) {
            if (!window.Weline || !window.Weline.Api) {
                return Promise.reject(new Error('Weline.Api is unavailable.'));
            }
            apiResources[provider] = window.Weline.Api.resource(provider);
        }
        return apiResources[provider];
    }

    function requestJson(panel, operation, payload) {
        var body = payload instanceof FormData ? formDataToObject(payload) : (payload || {});
        return getAddressApi(panel).then(function (AddressApi) {
            if (!AddressApi || typeof AddressApi[operation] !== 'function') {
                throw new Error('Address operation is unavailable: ' + operation);
            }
            return AddressApi[operation](body, {silent: true});
        });
    }

    function bindPanel(panel) {
        if (panel.dataset.addressAjaxBound === 'true') {
            return;
        }
        panel.dataset.addressAjaxBound = 'true';

        panel.querySelector('[data-address-new]').addEventListener('click', function () {
            openForm(panel);
        });

        panel.querySelectorAll('[data-address-close], [data-address-cancel]').forEach(function (button) {
            button.addEventListener('click', function () {
                closeForm(panel);
            });
        });

        panel.addEventListener('click', function (event) {
            var edit = event.target.closest('[data-address-edit]');
            var setDefault = event.target.closest('[data-address-default]');
            var remove = event.target.closest('[data-address-delete]');

            if (edit) {
                event.preventDefault();
                try {
                    openForm(panel, JSON.parse(edit.dataset.addressJson || '{}'));
                } catch (error) {
                    openForm(panel);
                }
                return;
            }

            if (setDefault) {
                event.preventDefault();
                var defaultData = new FormData();
                defaultData.append('id', setDefault.dataset.id || '');
                requestJson(panel, 'setDefault', defaultData).then(function (data) {
                    if (!data.success) {
                        showMessage(panel, data.message || labels.settingFailed, 'danger');
                        return;
                    }
                    panel.querySelectorAll('[data-address-card]').forEach(function (card) {
                        var current = card.dataset.addressId === setDefault.dataset.id;
                        card.classList.toggle('account-address-card--default', current);
                        card.querySelector('[data-address-default-badge]').hidden = !current;
                        card.querySelector('[data-address-default]').hidden = current;
                    });
                    showMessage(panel, data.message || labels.settingSuccess, 'success');
                }).catch(function (error) {
                    showMessage(panel, error.message || labels.requestFailed, 'danger');
                });
                return;
            }

            if (remove) {
                event.preventDefault();
                var deleteData = new FormData();
                deleteData.append('id', remove.dataset.id || '');
                requestJson(panel, 'delete', deleteData).then(function (data) {
                    if (!data.success) {
                        showMessage(panel, data.message || labels.deleteFailed, 'danger');
                        return;
                    }
                    var card = remove.closest('[data-address-card]');
                    if (card) {
                        card.remove();
                    }
                    panel.querySelector('[data-address-empty]').hidden = !!panel.querySelector('[data-address-card]');
                    showMessage(panel, data.message || labels.deleteSuccess, 'success');
                }).catch(function (error) {
                    showMessage(panel, error.message || labels.requestFailed, 'danger');
                });
            }
        });

        panel.querySelector('[data-address-form]').addEventListener('submit', function (event) {
            event.preventDefault();
            var form = event.currentTarget;
            var submit = form.querySelector('[type="submit"]');
            var originalText = submit.textContent;
            submit.disabled = true;
            submit.textContent = labels.saving;

            requestJson(panel, 'save', new FormData(form)).then(function (data) {
                if (!data.success) {
                    showMessage(panel, data.message || labels.saveFailed, 'danger');
                    return;
                }
                upsertCard(panel, data.data || {});
                closeForm(panel);
                showMessage(panel, data.message || labels.saveSuccess, 'success');
            }).catch(function (error) {
                showMessage(panel, error.message || labels.requestFailed, 'danger');
            }).finally(function () {
                submit.disabled = false;
                submit.textContent = originalText;
            });
        });
    }

    function bind() {
        document.querySelectorAll('[data-address-panel]').forEach(bindPanel);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
