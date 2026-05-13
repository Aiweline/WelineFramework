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
        confirmDeleteTitle: '\u786e\u8ba4\u5220\u9664\u8fd9\u4e2a\u5730\u5740\uff1f',
        confirmDeleteDesc: '\u5220\u9664\u540e\u65e0\u6cd5\u6062\u590d\uff0c\u8bf7\u5148\u786e\u8ba4\u8be5\u5730\u5740\u4e0d\u518d\u4f7f\u7528\u3002',
        confirmDelete: '\u786e\u8ba4\u5220\u9664',
        cancel: '\u53d6\u6d88',
        settingFailed: '\u8bbe\u7f6e\u5931\u8d25',
        settingSuccess: '\u8bbe\u7f6e\u6210\u529f',
        deleteFailed: '\u5220\u9664\u5931\u8d25',
        deleteSuccess: '\u5220\u9664\u6210\u529f',
        saveFailed: '\u4fdd\u5b58\u5931\u8d25',
        saveSuccess: '\u4fdd\u5b58\u6210\u529f',
        selectCountry: '\u8bf7\u9009\u62e9\u56fd\u5bb6/\u5730\u533a',
        selectProvinceFirst: '\u8bf7\u5148\u9009\u62e9\u56fd\u5bb6/\u5730\u533a',
        selectCityFirst: '\u8bf7\u5148\u9009\u62e9\u7701\u4efd',
        selectDistrictFirst: '\u8bf7\u5148\u9009\u62e9\u57ce\u5e02',
        selectProvince: '\u8bf7\u9009\u62e9\u7701\u4efd',
        selectCity: '\u8bf7\u9009\u62e9\u57ce\u5e02',
        selectDistrict: '\u8bf7\u9009\u62e9\u533a\u53bf',
        loadingRegions: '\u5730\u533a\u52a0\u8f7d\u4e2d...',
        saving: '\u4fdd\u5b58\u4e2d...',
        deleting: '\u5220\u9664\u4e2d...',
        requestFailed: '\u8bf7\u6c42\u5931\u8d25\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5'
    };

    var chinaFallbackRegions = [
        {region_id: 100001, parent_region_id: 0, country_code: 'CN', region_code: 'BJ', region_name: '\u5317\u4eac\u5e02', region_type: 'province'},
        {region_id: 100002, parent_region_id: 0, country_code: 'CN', region_code: 'SH', region_name: '\u4e0a\u6d77\u5e02', region_type: 'province'},
        {region_id: 100003, parent_region_id: 0, country_code: 'CN', region_code: 'GD', region_name: '\u5e7f\u4e1c\u7701', region_type: 'province'},
        {region_id: 100004, parent_region_id: 0, country_code: 'CN', region_code: 'ZJ', region_name: '\u6d59\u6c5f\u7701', region_type: 'province'},
        {region_id: 100005, parent_region_id: 0, country_code: 'CN', region_code: 'JS', region_name: '\u6c5f\u82cf\u7701', region_type: 'province'},
        {region_id: 100006, parent_region_id: 0, country_code: 'CN', region_code: 'SC', region_name: '\u56db\u5ddd\u7701', region_type: 'province'},
        {region_id: 100007, parent_region_id: 0, country_code: 'CN', region_code: 'HB', region_name: '\u6e56\u5317\u7701', region_type: 'province'},
        {region_id: 100008, parent_region_id: 0, country_code: 'CN', region_code: 'HN', region_name: '\u6e56\u5357\u7701', region_type: 'province'},
        {region_id: 100009, parent_region_id: 0, country_code: 'CN', region_code: 'FJ', region_name: '\u798f\u5efa\u7701', region_type: 'province'},
        {region_id: 100010, parent_region_id: 0, country_code: 'CN', region_code: 'SD', region_name: '\u5c71\u4e1c\u7701', region_type: 'province'},
        {region_id: 110001, parent_region_id: 100001, country_code: 'CN', region_code: 'BJ-BJ', region_name: '\u5317\u4eac\u5e02', region_type: 'city'},
        {region_id: 110002, parent_region_id: 100002, country_code: 'CN', region_code: 'SH-SH', region_name: '\u4e0a\u6d77\u5e02', region_type: 'city'},
        {region_id: 110003, parent_region_id: 100003, country_code: 'CN', region_code: 'GZ', region_name: '\u5e7f\u5dde\u5e02', region_type: 'city'},
        {region_id: 110004, parent_region_id: 100003, country_code: 'CN', region_code: 'SZ', region_name: '\u6df1\u5733\u5e02', region_type: 'city'},
        {region_id: 110005, parent_region_id: 100003, country_code: 'CN', region_code: 'DG', region_name: '\u4e1c\u839e\u5e02', region_type: 'city'},
        {region_id: 110006, parent_region_id: 100004, country_code: 'CN', region_code: 'HZ', region_name: '\u676d\u5dde\u5e02', region_type: 'city'},
        {region_id: 110007, parent_region_id: 100005, country_code: 'CN', region_code: 'NJ', region_name: '\u5357\u4eac\u5e02', region_type: 'city'},
        {region_id: 110008, parent_region_id: 100006, country_code: 'CN', region_code: 'CD', region_name: '\u6210\u90fd\u5e02', region_type: 'city'},
        {region_id: 110009, parent_region_id: 100007, country_code: 'CN', region_code: 'WH', region_name: '\u6b66\u6c49\u5e02', region_type: 'city'},
        {region_id: 110010, parent_region_id: 100008, country_code: 'CN', region_code: 'CS', region_name: '\u957f\u6c99\u5e02', region_type: 'city'},
        {region_id: 110011, parent_region_id: 100009, country_code: 'CN', region_code: 'XM', region_name: '\u53a6\u95e8\u5e02', region_type: 'city'},
        {region_id: 110012, parent_region_id: 100010, country_code: 'CN', region_code: 'QD', region_name: '\u9752\u5c9b\u5e02', region_type: 'city'},
        {region_id: 120001, parent_region_id: 110004, country_code: 'CN', region_code: 'NS', region_name: '\u5357\u5c71\u533a', region_type: 'district'},
        {region_id: 120002, parent_region_id: 110004, country_code: 'CN', region_code: 'FT', region_name: '\u798f\u7530\u533a', region_type: 'district'},
        {region_id: 120003, parent_region_id: 110004, country_code: 'CN', region_code: 'LH', region_name: '\u7f57\u6e56\u533a', region_type: 'district'},
        {region_id: 120004, parent_region_id: 110003, country_code: 'CN', region_code: 'TH', region_name: '\u5929\u6cb3\u533a', region_type: 'district'},
        {region_id: 120005, parent_region_id: 110003, country_code: 'CN', region_code: 'PY', region_name: '\u756a\u79ba\u533a', region_type: 'district'},
        {region_id: 120006, parent_region_id: 110001, country_code: 'CN', region_code: 'CY', region_name: '\u671d\u9633\u533a', region_type: 'district'},
        {region_id: 120007, parent_region_id: 110002, country_code: 'CN', region_code: 'PD', region_name: '\u6d66\u4e1c\u65b0\u533a', region_type: 'district'}
    ];

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

    function notice() {
        return window.Theme && window.Theme.Notice ? window.Theme.Notice : null;
    }

    function showMessage(panel, message, type) {
        var themeNotice = notice();
        if (themeNotice) {
            if (type === 'success') {
                themeNotice.success(message || '');
            } else {
                themeNotice.error(message || '');
            }
        }

        var box = panel.querySelector('[data-address-message]');
        if (!box) {
            return;
        }
        box.textContent = '';
        box.hidden = true;
    }

    function setText(root, selector, value) {
        var element = root.querySelector(selector);
        if (element) {
            element.textContent = text(value);
        }
        return element;
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
        card.setAttribute('data-address-id', data.id);
        setText(card, '[data-address-name]', data.name);
        var contact = setText(card, '[data-address-contact]', data.contact_name);
        var phone = setText(card, '[data-address-phone]', data.contact_phone);
        if (contact && !phone) {
            contact.textContent = [data.contact_name, data.contact_phone].map(text).filter(Boolean).join(' · ');
        }
        var full = card.querySelector('[data-address-full]');
        if (full) {
            full.setAttribute('aria-label', data.full_address || fullAddress(data));
            full.innerHTML = renderAddressParts(data);
        }
        setText(card, '[data-address-contact-label]', panel.dataset.addressPanel === 'delivery' ? labels.deliveryContact : labels.contact);

        var postal = card.querySelector('[data-address-postal]');
        if (postal) {
            postal.textContent = data.postal_code ? labels.postal + data.postal_code : '';
            postal.hidden = !data.postal_code;
        }

        var isDefault = !!data.is_default;
        card.classList.toggle('account-address-card--default', isDefault);
        var defaultBadge = card.querySelector('[data-address-default-badge]');
        var defaultButton = card.querySelector('[data-address-default]');
        var editButton = card.querySelector('[data-address-edit]');
        var deleteButton = card.querySelector('[data-address-delete]');
        if (defaultBadge) {
            defaultBadge.hidden = !isDefault;
        }
        if (defaultButton) {
            defaultButton.hidden = isDefault;
            defaultButton.dataset.id = data.id;
        }
        if (editButton) {
            editButton.dataset.addressJson = JSON.stringify(data);
        }
        if (deleteButton) {
            deleteButton.dataset.id = data.id;
        }
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
        ['name', 'contact_name', 'contact_phone', 'country_code', 'province_code', 'province_region_id', 'city_code', 'city_region_id', 'district_code', 'district_region_id', 'street', 'postal_code'].forEach(function (field) {
            var input = form.querySelector('[name="' + field + '"]');
            if (input) {
                input.value = data[field] || '';
            }
        });
        if (form.querySelector('[data-w-address]')) {
            ['country', 'province', 'city', 'district'].forEach(function (field) {
                var input = form.querySelector('[name="' + field + '"]');
                if (input) {
                    input.value = data[field] || '';
                }
            });
            form.dispatchEvent(new CustomEvent('weline:address:refresh', {detail: data}));
        } else {
            loadCountryOptions(form, data);
        }
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

    function requestJson(url, formData) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        }).then(function (response) {
            return response.text().then(function (body) {
                try {
                    return JSON.parse(body);
                } catch (error) {
                    throw new Error('Response is not valid JSON, status: ' + response.status);
                }
            });
        });
    }

    function fetchRegions(params) {
        params = params || {};
        if (Array.isArray(window.WelineShippingRegions)) {
            return Promise.resolve(filterEmbeddedRegions(params));
        }
        var query = new URLSearchParams();
        Object.keys(params).forEach(function (key) {
            if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                query.append(key, params[key]);
            }
        });

        var url = '/shipping/frontend/region/' + (params.parent_region_id || params.country_code ? 'children' : 'list');
        var queryText = query.toString();
        if (queryText) {
            url += '?' + queryText;
        }

        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.text().then(function (body) {
                var payload = body ? JSON.parse(body) : {};
                if (!response.ok || payload.success === false) {
                    throw new Error(payload.message || labels.requestFailed);
                }
                return Array.isArray(payload.data) ? payload.data : [];
            });
        });
    }

    function filterEmbeddedRegions(params) {
        var regions = (window.WelineShippingRegions || []).slice();
        var hasChinaProvince = regions.some(function (region) {
            return String(region.country_code || '') === 'CN' && String(region.region_type || '') === 'province';
        });
        if (!hasChinaProvince) {
            regions = regions.concat(chinaFallbackRegions);
        }
        if (params.parent_region_id) {
            return regions.filter(function (region) {
                return String(region.parent_region_id || '') === String(params.parent_region_id);
            });
        }
        if (params.country_code) {
            return regions.filter(function (region) {
                return String(region.country_code || '') === String(params.country_code)
                    && String(region.region_type || '') === 'province';
            });
        }
        var countries = regions.filter(function (region) {
            return String(region.region_type || '') === 'country';
        });
        var hasChinaCountry = countries.some(function (region) {
            return String(region.country_code || '') === 'CN';
        });
        if (!hasChinaCountry) {
            countries.unshift({region_id: 0, parent_region_id: 0, country_code: 'CN', region_code: 'CN', region_name: '\u4e2d\u56fd', region_type: 'country'});
        }
        return countries;
    }

    function replaceWithSelect(form, name, dataAttr, placeholder) {
        var field = form.querySelector('[name="' + name + '"]');
        if (!field) {
            return null;
        }
        if (field.tagName.toLowerCase() === 'select') {
            field.setAttribute(dataAttr, '');
            return field;
        }

        var select = document.createElement('select');
        select.name = name;
        select.required = field.required;
        select.setAttribute(dataAttr, '');
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        field.replaceWith(select);
        return select;
    }

    function setSelectLoading(select, placeholder) {
        if (!select) {
            return;
        }
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        select.disabled = true;
    }

    function fillRegionOptions(select, regions, placeholder, selectedValue) {
        if (!select) {
            return null;
        }
        var selectedOption = null;
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        regions.forEach(function (region) {
            var option = document.createElement('option');
            option.value = region.region_name || region.region_code || '';
            option.textContent = region.region_name || region.region_code || '';
            option.dataset.regionId = region.region_id || '';
            option.dataset.countryCode = region.country_code || '';
            option.dataset.regionCode = region.region_code || '';
            if (selectedValue && (selectedValue === option.value || selectedValue === option.dataset.regionCode || selectedValue === option.dataset.countryCode)) {
                option.selected = true;
                selectedOption = option;
            }
            select.appendChild(option);
        });
        select.disabled = regions.length === 0;
        return selectedOption || select.selectedOptions[0] || null;
    }

    function locationControls(form) {
        return {
            country: replaceWithSelect(form, 'country', 'data-address-country', labels.selectCountry),
            province: replaceWithSelect(form, 'province', 'data-address-province', labels.selectProvinceFirst),
            city: replaceWithSelect(form, 'city', 'data-address-city', labels.selectCityFirst),
            district: replaceWithSelect(form, 'district', 'data-address-district', labels.selectDistrictFirst)
        };
    }

    function loadCountryOptions(form, data) {
        var controls = locationControls(form);
        setSelectLoading(controls.country, labels.loadingRegions);
        setSelectLoading(controls.province, labels.selectProvinceFirst);
        setSelectLoading(controls.city, labels.selectCityFirst);
        setSelectLoading(controls.district, labels.selectDistrictFirst);

        return fetchRegions({}).then(function (countries) {
            var countryOption = fillRegionOptions(controls.country, countries, labels.selectCountry, data.country || '中国');
            if (countryOption) {
                return loadProvinceOptions(form, data, countryOption.dataset.countryCode);
            }
            return null;
        }).catch(function (error) {
            var panel = form.closest('[data-address-panel]');
            if (panel) {
                showMessage(panel, error.message || labels.requestFailed, 'danger');
            }
        });
    }

    function loadProvinceOptions(form, data, countryCode) {
        var controls = locationControls(form);
        if (!countryCode) {
            setSelectLoading(controls.province, labels.selectProvinceFirst);
            setSelectLoading(controls.city, labels.selectCityFirst);
            setSelectLoading(controls.district, labels.selectDistrictFirst);
            return Promise.resolve(null);
        }
        setSelectLoading(controls.province, labels.loadingRegions);
        setSelectLoading(controls.city, labels.selectCityFirst);
        setSelectLoading(controls.district, labels.selectDistrictFirst);

        return fetchRegions({ country_code: countryCode }).then(function (provinces) {
            var provinceOption = fillRegionOptions(controls.province, provinces, labels.selectProvince, data.province || '');
            if (provinceOption) {
                return loadCityOptions(form, data, provinceOption.dataset.regionId);
            }
            return null;
        });
    }

    function loadCityOptions(form, data, parentRegionId) {
        var controls = locationControls(form);
        if (!parentRegionId) {
            setSelectLoading(controls.city, labels.selectCityFirst);
            setSelectLoading(controls.district, labels.selectDistrictFirst);
            return Promise.resolve(null);
        }
        setSelectLoading(controls.city, labels.loadingRegions);
        setSelectLoading(controls.district, labels.selectDistrictFirst);

        return fetchRegions({ parent_region_id: parentRegionId }).then(function (cities) {
            var cityOption = fillRegionOptions(controls.city, cities, labels.selectCity, data.city || '');
            if (cityOption) {
                return loadDistrictOptions(form, data, cityOption.dataset.regionId);
            }
            return null;
        });
    }

    function loadDistrictOptions(form, data, parentRegionId) {
        var controls = locationControls(form);
        if (!parentRegionId) {
            setSelectLoading(controls.district, labels.selectDistrictFirst);
            return Promise.resolve(null);
        }
        setSelectLoading(controls.district, labels.loadingRegions);

        return fetchRegions({ parent_region_id: parentRegionId }).then(function (districts) {
            fillRegionOptions(controls.district, districts, labels.selectDistrict, data.district || '');
        });
    }

    function bindLocationCascade(form) {
        if (form.dataset.locationCascadeBound === 'true') {
            return;
        }
        form.dataset.locationCascadeBound = 'true';
        var controls = locationControls(form);

        controls.country.addEventListener('change', function () {
            var option = controls.country.selectedOptions[0];
            loadProvinceOptions(form, {}, option ? option.dataset.countryCode : '');
        });
        controls.province.addEventListener('change', function () {
            var option = controls.province.selectedOptions[0];
            loadCityOptions(form, {}, option ? option.dataset.regionId : '');
        });
        controls.city.addEventListener('change', function () {
            var option = controls.city.selectedOptions[0];
            loadDistrictOptions(form, {}, option ? option.dataset.regionId : '');
        });
    }

    function confirmDelete() {
        var themeNotice = notice();
        if (themeNotice && typeof themeNotice.confirm === 'function') {
            return themeNotice.confirm({
                title: labels.confirmDeleteTitle,
                message: labels.confirmDeleteDesc,
                confirmText: labels.confirmDelete,
                cancelText: labels.cancel
            });
        }
        return Promise.resolve(false);
    }

    function deleteAddress(panel, button) {
        return confirmDelete().then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            var deleteData = new FormData();
            deleteData.append('id', button.dataset.id || '');
            button.disabled = true;
            var originalText = button.textContent;
            button.textContent = labels.deleting;

            requestJson(panel.dataset.deleteUrl, deleteData).then(function (data) {
                if (!data.success) {
                    showMessage(panel, data.message || labels.deleteFailed, 'danger');
                    return;
                }
                var card = button.closest('[data-address-card]');
                if (card) {
                    card.remove();
                }
                panel.querySelector('[data-address-empty]').hidden = !!panel.querySelector('[data-address-card]');
                showMessage(panel, data.message || labels.deleteSuccess, 'success');
            }).catch(function (error) {
                showMessage(panel, error.message || labels.requestFailed, 'danger');
            }).finally(function () {
                button.disabled = false;
                button.textContent = originalText;
            });
        });
    }

    function bindPanel(panel) {
        if (panel.dataset.addressAjaxBound === 'true') {
            return;
        }
        panel.dataset.addressAjaxBound = 'true';
        var addressForm = panel.querySelector('[data-address-form]');
        if (!addressForm.querySelector('[data-w-address]')) {
            bindLocationCascade(addressForm);
            loadCountryOptions(addressForm, {});
        }

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
                requestJson(panel.dataset.defaultUrl, defaultData).then(function (data) {
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
                deleteAddress(panel, remove);
            }
        });

        panel.querySelector('[data-address-form]').addEventListener('submit', function (event) {
            event.preventDefault();
            var form = event.currentTarget;
            var submit = form.querySelector('[type="submit"]');
            var originalText = submit.textContent;
            submit.disabled = true;
            submit.textContent = labels.saving;

            requestJson(panel.dataset.saveUrl, new FormData(form)).then(function (data) {
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
