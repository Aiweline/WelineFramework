(function () {
    'use strict';

    if (window.WelineThemeAddress && window.WelineThemeAddress.boot) {
        window.WelineThemeAddressModule = window.WelineThemeAddress;
        if (window.Theme) {
            window.Theme.Address = window.WelineThemeAddress;
        }
        window.WelineThemeAddress.boot();
        return;
    }

    var order = ['country', 'province', 'city', 'district'];
    var autoCode = 0;
    var groups = {};
    var regionSources = {};
    var defaultSourceUrl = '/shipping/frontend/region/list';
    var defaultLabels = {
        country: '\u56fd\u5bb6/\u5730\u533a',
        province: '\u7701\u4efd',
        city: '\u57ce\u5e02',
        district: '\u533a\u53bf',
        empty: '\u6682\u65e0\u53ef\u9009\u5730\u533a',
        manual: '\u53ef\u76f4\u63a5\u8f93\u5165\u8be5\u5730\u533a',
        selectCountry: '\u8bf7\u9009\u62e9\u56fd\u5bb6/\u5730\u533a',
        selectProvince: '\u8bf7\u9009\u62e9\u7701\u4efd',
        selectCity: '\u8bf7\u9009\u62e9\u57ce\u5e02',
        selectDistrict: '\u8bf7\u9009\u62e9\u533a\u53bf',
        selectCountryFirst: '\u8bf7\u5148\u9009\u62e9\u56fd\u5bb6/\u5730\u533a',
        selectProvinceFirst: '\u8bf7\u5148\u9009\u62e9\u7701\u4efd',
        selectCityFirst: '\u8bf7\u5148\u9009\u62e9\u57ce\u5e02'
    };
    var chinaFallback = [
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

    function escapeHtml(value) {
        return text(value).replace(/[&<>"']/g, function (ch) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[ch];
        });
    }

    function labelOf(region) {
        return text(region.region_name || region.region_code || region.country_code);
    }

    function matchesValue(region, value) {
        value = text(value);
        return value !== '' && (
            value === text(region.region_name) ||
            value === text(region.region_default_name) ||
            value === text(region.region_code) ||
            value === text(region.country_code)
        );
    }

    function splitFilter(value) {
        return text(value).split('|').map(function (item) {
            return item.trim();
        }).filter(Boolean);
    }

    function readConfig(root) {
        try {
            return JSON.parse(root.getAttribute('data-address-config') || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function labelsFor(config) {
        return Object.assign({}, defaultLabels, config.labels || {});
    }

    function normalizeRegions(regionList) {
        var regions = Array.isArray(regionList) ? regionList.slice() : [];
        var hasChinaCountry = regions.some(function (region) {
            return text(region.country_code) === 'CN' && text(region.region_type) === 'country';
        });
        var hasChinaProvince = regions.some(function (region) {
            return text(region.country_code) === 'CN' && text(region.region_type) === 'province';
        });
        if (!hasChinaCountry) {
            regions.unshift({region_id: 0, parent_region_id: 0, country_code: 'CN', region_code: 'CN', region_name: '\u4e2d\u56fd', region_type: 'country'});
        }
        if (!hasChinaProvince) {
            regions = regions.concat(chinaFallback);
        }
        return regions;
    }

    function fallbackRegions() {
        return normalizeRegions([]);
    }

    function regionsFromPayload(payload) {
        if (Array.isArray(payload)) {
            return payload;
        }
        if (!payload || typeof payload !== 'object') {
            return [];
        }
        if (Array.isArray(payload.data)) {
            return payload.data;
        }
        if (payload.data && Array.isArray(payload.data.regions)) {
            return payload.data.regions;
        }
        if (Array.isArray(payload.regions)) {
            return payload.regions;
        }
        return [];
    }

    function loadRegions(sourceUrl) {
        sourceUrl = text(sourceUrl || defaultSourceUrl);
        if (Array.isArray(window.WelineShippingRegions)) {
            return Promise.resolve(normalizeRegions(window.WelineShippingRegions));
        }
        if (!window.fetch) {
            return Promise.resolve(fallbackRegions());
        }
        if (!regionSources[sourceUrl]) {
            regionSources[sourceUrl] = fetch(sourceUrl, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (response) {
                return response.text().then(function (body) {
                    if (!response.ok) {
                        throw new Error('Region response status ' + response.status);
                    }
                    return body ? JSON.parse(body) : {};
                });
            }).then(function (payload) {
                return normalizeRegions(regionsFromPayload(payload));
            }).catch(function () {
                return fallbackRegions();
            });
        }

        return regionSources[sourceUrl];
    }

    function groupFor(code, sourceUrl) {
        if (!groups[code]) {
            groups[code] = {code: code, controls: {}, state: {}, regions: fallbackRegions(), fixed: {}, cascade: true, sourceUrl: sourceUrl || defaultSourceUrl};
        }
        return groups[code];
    }

    function createHidden(name, root) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        root.appendChild(input);
        return input;
    }

    function findOrCreateField(root, form, name) {
        var field = form ? form.querySelector('[name="' + name + '"]') : null;
        if (!field) {
            field = createHidden(name, root);
        }
        if (field.tagName && field.tagName.toLowerCase() === 'input') {
            field.type = 'hidden';
        }
        var holder = field.closest && field.closest('.account-address-form__field');
        if (holder) {
            holder.hidden = true;
        }
        return field;
    }

    function renderRoot(root, config, levels) {
        var labels = labelsFor(config);
        root.classList.toggle('w-address--single', levels.length === 1);
        root.innerHTML = levels.map(function (level) {
            var placeholder = level === 'country' ? labels.selectCountry : (level === 'province' ? labels.selectCountryFirst : (level === 'city' ? labels.selectProvinceFirst : labels.selectCityFirst));
            return '<div class="w-address__item" data-address-level="' + level + '"><label class="w-address__label">' + escapeHtml(labels[level] || level) + '</label><div class="w-address__control"><input class="w-address__input" type="text" autocomplete="off" placeholder="' + escapeHtml(placeholder) + '"><span class="w-address__arrow">\u25be</span></div><div class="w-address__menu"></div></div>';
        }).join('');
    }

    function optionsFor(group, level) {
        var filter = splitFilter(group.fixed[level] || '');
        var regions = group.regions;

        function provincesInCountry(country) {
            if (!country) {
                return [];
            }
            return regions.filter(function (region) {
                return text(region.region_type) === 'province' && text(region.country_code) === text(country.country_code);
            });
        }

        function citiesInProvince(province) {
            if (!province) {
                return [];
            }
            return regions.filter(function (region) {
                return text(region.region_type) === 'city' && text(region.parent_region_id) === text(province.region_id);
            });
        }

        if (level === 'country') {
            return regions.filter(function (region) {
                if (text(region.region_type) !== 'country') {
                    return false;
                }
                return !filter.length || filter.some(function (item) {
                    return matchesValue(region, item);
                });
            });
        }

        if (level === 'province') {
            var country = group.state.country;
            if (!country && !filter.length) {
                return [];
            }
            return regions.filter(function (region) {
                if (text(region.region_type) !== 'province') {
                    return false;
                }
                if (country && text(region.country_code) !== text(country.country_code)) {
                    return false;
                }
                return !filter.length || filter.some(function (item) {
                    return matchesValue(region, item);
                });
            });
        }

        if (level === 'city') {
            var province = group.state.province;
            var countryForCity = group.state.country;
            var provinceIds = province ? [text(province.region_id)] : provincesInCountry(countryForCity).map(function (region) {
                return text(region.region_id);
            });
            if (!provinceIds.length && !filter.length) {
                return [];
            }
            return regions.filter(function (region) {
                if (text(region.region_type) !== 'city') {
                    return false;
                }
                if (provinceIds.length && provinceIds.indexOf(text(region.parent_region_id)) === -1) {
                    return false;
                }
                return !filter.length || filter.some(function (item) {
                    return matchesValue(region, item);
                });
            });
        }

        var city = group.state.city;
        var districtCities = city ? [city] : [];
        if (!districtCities.length && group.state.province) {
            districtCities = citiesInProvince(group.state.province);
        }
        if (!districtCities.length && group.state.country) {
            provincesInCountry(group.state.country).forEach(function (provinceItem) {
                districtCities = districtCities.concat(citiesInProvince(provinceItem));
            });
        }
        var cityIds = districtCities.map(function (region) {
            return text(region.region_id);
        });
        if (!cityIds.length && !filter.length) {
            return [];
        }
        return regions.filter(function (region) {
            if (text(region.region_type) !== 'district') {
                return false;
            }
            if (cityIds.length && cityIds.indexOf(text(region.parent_region_id)) === -1) {
                return false;
            }
            return !filter.length || filter.some(function (item) {
                return matchesValue(region, item);
            });
        });
    }

    function findRegion(group, level, value) {
        return optionsFor(group, level).find(function (region) {
            return matchesValue(region, value);
        }) || null;
    }

    function firstAllowed(group, level) {
        var opts = optionsFor(group, level);
        return opts.length ? opts[0] : null;
    }

    function refreshState(group) {
        order.forEach(function (level) {
            group.state[level] = null;
        });
        if (group.controls.country && group.controls.country.field.value) {
            group.state.country = findRegion(group, 'country', group.controls.country.field.value);
        }
        if (!group.state.country && group.fixed.country) {
            group.state.country = findRegion(group, 'country', splitFilter(group.fixed.country)[0]);
        }
        if (!group.state.country && group.controls.country && !group.controls.country.field.value) {
            group.state.country = findRegion(group, 'country', 'CN') || firstAllowed(group, 'country');
        }
        if (group.state.country && group.controls.country && !group.controls.country.field.value) {
            group.controls.country.field.value = labelOf(group.state.country);
        }
        if (group.controls.province && group.controls.province.field.value) {
            group.state.province = findRegion(group, 'province', group.controls.province.field.value);
        }
        if (!group.state.province && group.fixed.province) {
            group.state.province = findRegion(group, 'province', splitFilter(group.fixed.province)[0]);
        }
        if (group.controls.city && group.controls.city.field.value) {
            group.state.city = findRegion(group, 'city', group.controls.city.field.value);
        }
        if (!group.state.city && group.fixed.city) {
            group.state.city = findRegion(group, 'city', splitFilter(group.fixed.city)[0]);
        }
        if (group.controls.district && group.controls.district.field.value) {
            group.state.district = findRegion(group, 'district', group.controls.district.field.value);
        }
    }

    function syncMetadata(group) {
        var root = null;
        Object.keys(group.controls).some(function (level) {
            root = group.controls[level].root;
            return !!root;
        });
        if (!root) {
            return;
        }
        var form = root.closest('form');
        function metadataField(name) {
            return findOrCreateField(root, form, name);
        }
        var country = group.state.country;
        var province = group.state.province;
        var city = group.state.city;
        var district = group.state.district;
        metadataField('country_code').value = country ? text(country.country_code || country.region_code) : '';
        metadataField('province_code').value = province ? text(province.region_code) : '';
        metadataField('province_region_id').value = province ? text(province.region_id) : '';
        metadataField('city_code').value = city ? text(city.region_code) : '';
        metadataField('city_region_id').value = city ? text(city.region_id) : '';
        metadataField('district_code').value = district ? text(district.region_code) : '';
        metadataField('district_region_id').value = district ? text(district.region_id) : '';
    }

    function clearAfter(group, level) {
        if (!group.cascade) {
            return;
        }
        var start = order.indexOf(level) + 1;
        for (var i = start; i < order.length; i++) {
            var control = group.controls[order[i]];
            if (control) {
                control.field.value = '';
                group.state[order[i]] = null;
            }
        }
    }

    function isDisabled(group, level) {
        if (!group.cascade) {
            return false;
        }
        if (level === 'province') {
            return !group.state.country && !group.fixed.country && !group.controls.country;
        }
        if (level === 'city') {
            return !group.state.province && !group.fixed.province && !group.controls.province && !group.state.country && !group.fixed.country && !group.controls.country;
        }
        if (level === 'district') {
            return !group.state.city && !group.fixed.city && !group.controls.city && !group.state.province && !group.fixed.province && !group.controls.province && !group.state.country && !group.fixed.country && !group.controls.country;
        }
        return false;
    }

    function placeholderFor(group, level) {
        var labels = group.labels || defaultLabels;
        if (level === 'country') {
            return labels.selectCountry;
        }
        if (level === 'province') {
            return group.state.country || group.fixed.country ? labels.selectProvince : labels.selectCountryFirst;
        }
        if (level === 'city') {
            return group.state.province || group.fixed.province || group.state.country || group.fixed.country ? labels.selectCity : labels.selectProvinceFirst;
        }
        return group.state.city || group.fixed.city || group.state.province || group.fixed.province || group.state.country || group.fixed.country ? labels.selectDistrict : labels.selectCityFirst;
    }

    function renderMenu(group, control, keyword) {
        var labels = group.labels || defaultLabels;
        var needle = text(keyword).trim().toLowerCase();
        var items = optionsFor(group, control.level).filter(function (region) {
            return !needle ||
                labelOf(region).toLowerCase().indexOf(needle) > -1 ||
                text(region.region_default_name).toLowerCase().indexOf(needle) > -1 ||
                text(region.region_code).toLowerCase().indexOf(needle) > -1 ||
                text(region.country_code).toLowerCase().indexOf(needle) > -1;
        });
        if (!items.length) {
            control.menu.innerHTML = '<div class="w-address__empty">' + escapeHtml(canUseManualInput(group, control) && needle ? labels.manual : labels.empty) + '</div>';
            return;
        }
        control.menu.innerHTML = items.map(function (region, index) {
            return '<button type="button" class="w-address__option" data-index="' + index + '">' + escapeHtml(labelOf(region)) + '</button>';
        }).join('');
        control.menu.querySelectorAll('.w-address__option').forEach(function (button) {
            button.addEventListener('click', function () {
                var region = items[Number(button.dataset.index)];
                control.field.value = labelOf(region);
                group.state[control.level] = region;
                clearAfter(group, control.level);
                updateGroup(group);
                closeMenus(group);
                control.field.dispatchEvent(new Event('change', {bubbles: true}));
            });
        });
    }

    function canUseManualInput(group, control) {
        return control.level !== 'country' && control.searchable && !isDisabled(group, control.level) && optionsFor(group, control.level).length === 0;
    }

    function syncManualInput(group, control) {
        if (!canUseManualInput(group, control)) {
            return;
        }
        control.field.value = text(control.input.value).trim();
        group.state[control.level] = null;
        clearAfter(group, control.level);
    }

    function closeMenus(group) {
        Object.keys(group.controls).forEach(function (level) {
            group.controls[level].item.classList.remove('is-open');
        });
    }

    function updateControl(group, control) {
        refreshState(group);
        var current = group.state[control.level];
        control.input.value = current ? labelOf(current) : control.field.value;
        control.input.placeholder = placeholderFor(group, control.level);
        var disabled = isDisabled(group, control.level);
        control.input.disabled = disabled;
        control.item.querySelector('.w-address__control').classList.toggle('is-disabled', disabled);
    }

    function updateGroup(group) {
        Object.keys(group.controls).forEach(function (level) {
            updateControl(group, group.controls[level]);
        });
        syncMetadata(group);
    }

    function bindControl(group, control) {
        if (control.bound) {
            return;
        }
        control.bound = true;
        control.input.addEventListener('focus', function () {
            if (control.input.disabled) {
                return;
            }
            closeMenus(group);
            control.item.classList.add('is-open');
            renderMenu(group, control, '');
            if (!control.searchable) {
                control.input.select();
            }
        });
        control.input.addEventListener('input', function () {
            if (!control.searchable) {
                control.input.value = group.state[control.level] ? labelOf(group.state[control.level]) : control.field.value;
                return;
            }
            control.item.classList.add('is-open');
            renderMenu(group, control, control.input.value);
            syncManualInput(group, control);
        });
        control.input.addEventListener('blur', function () {
            syncManualInput(group, control);
        });
    }

    function mount(root) {
        if (!root || root.dataset.wAddressReady === 'true') {
            return;
        }
        var config = readConfig(root);
        var levels = text(config.for || 'country|province|city').split('|').map(function (level) {
            return level.trim();
        }).filter(function (level) {
            return order.indexOf(level) > -1;
        });
        if (!levels.length) {
            levels = ['country', 'province', 'city'];
        }
        levels = order.filter(function (level) {
            return levels.indexOf(level) > -1;
        });
        root.dataset.wAddressReady = 'true';
        var code = text(config.code || '');
        if (!code) {
            code = 'w-address-auto-' + (++autoCode);
        }
        root.dataset.addressCode = code;
        var group = groupFor(code, config.sourceUrl || defaultSourceUrl);
        group.cascade = config.cascade !== false;
        group.labels = labelsFor(config);
        group.sourceUrl = config.sourceUrl || group.sourceUrl || defaultSourceUrl;
        ['country', 'province', 'city'].forEach(function (level) {
            if (config.filters && config.filters[level]) {
                group.fixed[level] = config.filters[level];
            }
        });
        var form = root.closest('form');
        var names = config.names || {};
        renderRoot(root, config, levels);
        var firstField = null;
        levels.forEach(function (level) {
            var item = root.querySelector('[data-address-level="' + level + '"]');
            var fieldName = names[level] || level;
            var field = findOrCreateField(root, form, fieldName);
            if (!firstField) {
                firstField = field;
            }
            group.controls[level] = {
                root: root,
                item: item,
                level: level,
                field: field,
                input: item.querySelector('.w-address__input'),
                menu: item.querySelector('.w-address__menu'),
                searchable: config.searchable !== false
            };
            bindControl(group, group.controls[level]);
        });
        var anchor = firstField && firstField.closest && firstField.closest('.account-address-form__field');
        if (anchor && anchor.parentNode) {
            anchor.parentNode.insertBefore(root, anchor);
        }
        if (form && !form.dataset.wAddressRefreshBound) {
            form.dataset.wAddressRefreshBound = 'true';
            form.addEventListener('weline:address:refresh', function () {
                Object.keys(groups).forEach(function (key) {
                    updateGroup(groups[key]);
                });
            });
        }
        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                closeMenus(group);
            }
        });
        updateGroup(group);
        loadRegions(group.sourceUrl).then(function (regions) {
            group.regions = regions;
            updateGroup(group);
        });
    }

    function boot() {
        document.querySelectorAll('[data-w-address]').forEach(mount);
    }

    window.WelineThemeAddress = {boot: boot, groups: groups};
    window.WelineThemeAddressModule = window.WelineThemeAddress;
    if (window.Weline && window.Weline.Theme) {
        window.Weline.Theme.Address = window.WelineThemeAddress;
    }
    if (window.Theme) {
        window.Theme.Address = window.WelineThemeAddress;
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    document.addEventListener('weline:account-section-ready', boot);
})();
