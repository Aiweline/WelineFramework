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
    var regionApiPromise = null;
    var defaultSourceUrl = frontendRoute('/shipping/frontend/region/list');
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
    var englishLabels = {
        country: 'Country/Region',
        province: 'Province',
        city: 'City',
        district: 'District',
        empty: 'No regions available',
        manual: 'You can enter this region directly',
        selectCountry: 'Please select country/region',
        selectProvince: 'Please select province',
        selectCity: 'Please select city',
        selectDistrict: 'Please select district',
        selectCountryFirst: 'Please select country/region first',
        selectProvinceFirst: 'Please select province first',
        selectCityFirst: 'Please select city first'
    };
    var chinaFallback = [
        {region_id: 100001, parent_region_id: 0, country_code: 'CN', region_code: 'BJ', region_name: '\u5317\u4eac\u5e02', region_type: 'province', postal_code: '100000'},
        {region_id: 100002, parent_region_id: 0, country_code: 'CN', region_code: 'SH', region_name: '\u4e0a\u6d77\u5e02', region_type: 'province', postal_code: '200000'},
        {region_id: 100003, parent_region_id: 0, country_code: 'CN', region_code: 'GD', region_name: '\u5e7f\u4e1c\u7701', region_type: 'province', postal_code: '510000'},
        {region_id: 100004, parent_region_id: 0, country_code: 'CN', region_code: 'ZJ', region_name: '\u6d59\u6c5f\u7701', region_type: 'province', postal_code: '310000'},
        {region_id: 100005, parent_region_id: 0, country_code: 'CN', region_code: 'JS', region_name: '\u6c5f\u82cf\u7701', region_type: 'province', postal_code: '210000'},
        {region_id: 100006, parent_region_id: 0, country_code: 'CN', region_code: 'SC', region_name: '\u56db\u5ddd\u7701', region_type: 'province', postal_code: '610000'},
        {region_id: 100007, parent_region_id: 0, country_code: 'CN', region_code: 'HB', region_name: '\u6e56\u5317\u7701', region_type: 'province', postal_code: '430000'},
        {region_id: 100008, parent_region_id: 0, country_code: 'CN', region_code: 'HN', region_name: '\u6e56\u5357\u7701', region_type: 'province', postal_code: '410000'},
        {region_id: 100009, parent_region_id: 0, country_code: 'CN', region_code: 'FJ', region_name: '\u798f\u5efa\u7701', region_type: 'province', postal_code: '350000'},
        {region_id: 100010, parent_region_id: 0, country_code: 'CN', region_code: 'SD', region_name: '\u5c71\u4e1c\u7701', region_type: 'province', postal_code: '250000'},
        {region_id: 110001, parent_region_id: 100001, country_code: 'CN', region_code: 'BJ-BJ', region_name: '\u5317\u4eac\u5e02', region_type: 'city', postal_code: '100000'},
        {region_id: 110002, parent_region_id: 100002, country_code: 'CN', region_code: 'SH-SH', region_name: '\u4e0a\u6d77\u5e02', region_type: 'city', postal_code: '200000'},
        {region_id: 110003, parent_region_id: 100003, country_code: 'CN', region_code: 'GZ', region_name: '\u5e7f\u5dde\u5e02', region_type: 'city', postal_code: '510000'},
        {region_id: 110004, parent_region_id: 100003, country_code: 'CN', region_code: 'SZ', region_name: '\u6df1\u5733\u5e02', region_type: 'city', postal_code: '518000'},
        {region_id: 110005, parent_region_id: 100003, country_code: 'CN', region_code: 'DG', region_name: '\u4e1c\u839e\u5e02', region_type: 'city', postal_code: '523000'},
        {region_id: 110006, parent_region_id: 100004, country_code: 'CN', region_code: 'HZ', region_name: '\u676d\u5dde\u5e02', region_type: 'city', postal_code: '310000'},
        {region_id: 110007, parent_region_id: 100005, country_code: 'CN', region_code: 'NJ', region_name: '\u5357\u4eac\u5e02', region_type: 'city', postal_code: '210000'},
        {region_id: 110008, parent_region_id: 100006, country_code: 'CN', region_code: 'CD', region_name: '\u6210\u90fd\u5e02', region_type: 'city', postal_code: '610000'},
        {region_id: 110009, parent_region_id: 100007, country_code: 'CN', region_code: 'WH', region_name: '\u6b66\u6c49\u5e02', region_type: 'city', postal_code: '430000'},
        {region_id: 110010, parent_region_id: 100008, country_code: 'CN', region_code: 'CS', region_name: '\u957f\u6c99\u5e02', region_type: 'city', postal_code: '410000'},
        {region_id: 110011, parent_region_id: 100009, country_code: 'CN', region_code: 'XM', region_name: '\u53a6\u95e8\u5e02', region_type: 'city', postal_code: '361000'},
        {region_id: 110012, parent_region_id: 100010, country_code: 'CN', region_code: 'QD', region_name: '\u9752\u5c9b\u5e02', region_type: 'city', postal_code: '266000'},
        {region_id: 120001, parent_region_id: 110004, country_code: 'CN', region_code: 'NS', region_name: '\u5357\u5c71\u533a', region_type: 'district', postal_code: '518052'},
        {region_id: 120002, parent_region_id: 110004, country_code: 'CN', region_code: 'FT', region_name: '\u798f\u7530\u533a', region_type: 'district', postal_code: '518000'},
        {region_id: 120003, parent_region_id: 110004, country_code: 'CN', region_code: 'LH', region_name: '\u7f57\u6e56\u533a', region_type: 'district', postal_code: '518001'},
        {region_id: 120004, parent_region_id: 110003, country_code: 'CN', region_code: 'TH', region_name: '\u5929\u6cb3\u533a', region_type: 'district', postal_code: '510630'},
        {region_id: 120005, parent_region_id: 110003, country_code: 'CN', region_code: 'PY', region_name: '\u756a\u79ba\u533a', region_type: 'district', postal_code: '511400'},
        {region_id: 120006, parent_region_id: 110001, country_code: 'CN', region_code: 'CY', region_name: '\u671d\u9633\u533a', region_type: 'district', postal_code: '100020'},
        {region_id: 120007, parent_region_id: 110002, country_code: 'CN', region_code: 'PD', region_name: '\u6d66\u4e1c\u65b0\u533a', region_type: 'district', postal_code: '200120'}
    ];

    function text(value) {
        return value == null ? '' : String(value);
    }

    function frontendRoute(path) {
        path = text(path);
        if (!path) {
            return '';
        }
        if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(path)) {
            return path;
        }
        if (typeof window.frontend_url === 'function') {
            return window.frontend_url(path);
        }
        if (typeof window.url === 'function') {
            return window.url(path);
        }
        return path;
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
            var config = JSON.parse(root.getAttribute('data-address-config') || '{}') || {};
            config.sourceUrl = frontendRoute(config.sourceUrl || defaultSourceUrl);
            return config;
        } catch (error) {
            return {sourceUrl: defaultSourceUrl};
        }
    }

    function currentLocale() {
        if (window.site && window.site.lang) {
            return text(window.site.lang);
        }
        if (window.Weline && window.Weline.config) {
            return text(window.Weline.config.currentLang || (window.Weline.config.i18n && window.Weline.config.i18n.currentLang));
        }
        var match = text(window.location.pathname).match(/\/([a-z]{2}(?:_[A-Za-z0-9]+)+)(?:\/|$)/i);
        return match ? text(match[1]) : '';
    }

    function labelsFor(config) {
        var labels = Object.assign({}, defaultLabels, config.labels || {});
        if (!/^zh/i.test(currentLocale())) {
            Object.keys(englishLabels).forEach(function (key) {
                if (!labels[key] || labels[key] === defaultLabels[key]) {
                    labels[key] = englishLabels[key];
                }
            });
        }
        return labels;
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

    function getRegionApi() {
        if (!regionApiPromise) {
            regionApiPromise = Promise.resolve(window.Weline.Api.resource('region'));
        }

        return regionApiPromise;
    }

    function loadRegions(sourceUrl, countryCode) {
        sourceUrl = text(sourceUrl || defaultSourceUrl);
        countryCode = text(countryCode).toUpperCase();
        var cacheKey = sourceUrl + '|' + countryCode;
        if (Array.isArray(window.WelineShippingRegions) && !countryCode) {
            return Promise.resolve(normalizeRegions(window.WelineShippingRegions));
        }
        if (!window.Weline || !window.Weline.Api) {
            return Promise.resolve(fallbackRegions());
        }
        if (!regionSources[cacheKey]) {
            regionSources[cacheKey] = getRegionApi().then(function (RegionApi) {
                var params = {};
                if (countryCode) {
                    params.country_code = countryCode;
                }
                return RegionApi.list(params, {silent: true});
            }).then(function (payload) {
                return normalizeRegions(regionsFromPayload(payload));
            }).catch(function () {
                return fallbackRegions();
            });
        }

        return regionSources[cacheKey];
    }

    function groupFor(code, sourceUrl) {
        if (!groups[code]) {
            groups[code] = {code: code, controls: {}, state: {}, regions: fallbackRegions(), fixed: {}, cascade: true, sourceUrl: frontendRoute(sourceUrl || defaultSourceUrl)};
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

    function synthesizeCountry(countryCode, countryName) {
        countryCode = text(countryCode).toUpperCase();
        if (!countryCode) {
            return null;
        }
        return {
            region_id: 0,
            parent_region_id: 0,
            country_code: countryCode,
            region_code: countryCode,
            region_name: text(countryName) || countryCode,
            region_type: 'country'
        };
    }

    function ensureCountryInRegions(group, countryCode, countryName) {
        countryCode = text(countryCode).toUpperCase();
        if (!group || !countryCode) {
            return;
        }
        group.regions = group.regions || [];
        var exists = group.regions.some(function (region) {
            return text(region.region_type) === 'country'
                && (text(region.country_code) === countryCode || text(region.region_code) === countryCode);
        });
        if (exists) {
            return;
        }
        group.regions.unshift(synthesizeCountry(countryCode, countryName || countryCode));
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

    function metadataValue(group, name) {
        var root = null;
        Object.keys(group.controls).some(function (level) {
            root = group.controls[level].root;
            return !!root;
        });
        if (!root) {
            return '';
        }
        var form = root.closest('form');
        if (!form) {
            return '';
        }
        var field = form.querySelector('[name="' + name + '"]');
        return field ? text(field.value) : '';
    }

    function findRegionByMetadata(group, level) {
        var regionId = '';
        var regionCode = '';
        var countryCode = metadataValue(group, 'country_code');

        if (level === 'country') {
            if (!countryCode) {
                return null;
            }
            return optionsFor(group, level).find(function (region) {
                return countryCode === text(region.country_code) || countryCode === text(region.region_code);
            }) || null;
        }

        if (level === 'province') {
            regionId = metadataValue(group, 'province_region_id');
            regionCode = metadataValue(group, 'province_code');
        } else if (level === 'city') {
            regionId = metadataValue(group, 'city_region_id');
            regionCode = metadataValue(group, 'city_code');
        } else if (level === 'district') {
            regionId = metadataValue(group, 'district_region_id');
            regionCode = metadataValue(group, 'district_code');
        }

        if (!regionId && !regionCode) {
            return null;
        }

        return optionsFor(group, level).find(function (region) {
            return (regionId && regionId === text(region.region_id)) ||
                (regionCode && regionCode === text(region.region_code));
        }) || null;
    }

    function refreshState(group) {
        order.forEach(function (level) {
            group.state[level] = null;
        });
        if (group.controls.country && group.controls.country.field.value) {
            group.state.country = findRegion(group, 'country', group.controls.country.field.value);
        }
        if (!group.state.country) {
            group.state.country = findRegionByMetadata(group, 'country');
        }
        if (!group.state.country && group.fixed.country) {
            group.state.country = findRegion(group, 'country', splitFilter(group.fixed.country)[0]);
        }
        if (!group.state.country && !group.controls.country) {
            // 顶部已锁定国家时，即使地区库没有该国节点，也合成国家状态，禁止回落到 CN。
            var lockedCountry = text(group.fixed.country) || metadataValue(group, 'country_code');
            if (lockedCountry) {
                group.state.country = findRegion(group, 'country', lockedCountry)
                    || synthesizeCountry(lockedCountry, metadataValue(group, 'country') || lockedCountry);
            } else {
                group.state.country = firstAllowed(group, 'country');
            }
        }
        if (!group.state.country && group.controls.country && !group.controls.country.field.value) {
            group.state.country = findRegion(group, 'country', 'CN') || firstAllowed(group, 'country');
        }
        if (group.state.country && group.controls.country) {
            group.controls.country.field.value = labelOf(group.state.country);
        }
        if (group.controls.province && group.controls.province.field.value) {
            group.state.province = findRegion(group, 'province', group.controls.province.field.value);
        }
        if (!group.state.province) {
            group.state.province = findRegionByMetadata(group, 'province');
        }
        if (!group.state.province && group.fixed.province) {
            group.state.province = findRegion(group, 'province', splitFilter(group.fixed.province)[0]);
        }
        if (group.state.province && group.controls.province) {
            group.controls.province.field.value = labelOf(group.state.province);
        }
        if (group.controls.city && group.controls.city.field.value) {
            group.state.city = findRegion(group, 'city', group.controls.city.field.value);
        }
        if (!group.state.city) {
            group.state.city = findRegionByMetadata(group, 'city');
        }
        if (!group.state.city && group.fixed.city) {
            group.state.city = findRegion(group, 'city', splitFilter(group.fixed.city)[0]);
        }
        if (group.state.city && group.controls.city) {
            group.controls.city.field.value = labelOf(group.state.city);
        }
        if (group.controls.district && group.controls.district.field.value) {
            group.state.district = findRegion(group, 'district', group.controls.district.field.value);
        }
        if (!group.state.district) {
            group.state.district = findRegionByMetadata(group, 'district');
        }
        if (group.state.district && group.controls.district) {
            group.controls.district.field.value = labelOf(group.state.district);
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
        syncPostalField(group, form);
    }

    function resolvePostalCode(group) {
        var district = group.state.district;
        var city = group.state.city;
        var province = group.state.province;
        var region = district || city || province || null;
        if (!region) {
            return '';
        }
        var postal = text(region.postal_code || '').trim();
        if (postal) {
            return postal;
        }
        var regionId = text(region.region_id);
        var regionCode = text(region.region_code);
        var fallback = (group.regions || []).find(function (item) {
            return (regionId && regionId === text(item.region_id))
                || (regionCode && regionCode === text(item.region_code) && text(item.region_type) === text(region.region_type));
        });
        return fallback ? text(fallback.postal_code || '').trim() : '';
    }

    function syncPostalField(group, form) {
        if (!form) {
            return;
        }
        var postalField = form.querySelector('[name="postal_code"]');
        if (!postalField) {
            return;
        }
        postalField.value = resolvePostalCode(group);
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

    function regionMatchesKeyword(region, needle) {
        if (!needle) {
            return true;
        }
        return labelOf(region).toLowerCase().indexOf(needle) > -1 ||
            text(region.region_default_name).toLowerCase().indexOf(needle) > -1 ||
            text(region.region_code).toLowerCase().indexOf(needle) > -1 ||
            text(region.country_code).toLowerCase().indexOf(needle) > -1;
    }

    function findRegionById(group, regionId) {
        regionId = text(regionId);
        if (!regionId || regionId === '0') {
            return null;
        }
        return (group.regions || []).find(function (region) {
            return text(region.region_id) === regionId;
        }) || null;
    }

    function parentRegion(group, region) {
        if (!region) {
            return null;
        }
        var parentId = text(region.parent_region_id);
        if (parentId && parentId !== '0') {
            return findRegionById(group, parentId);
        }
        if (text(region.region_type) === 'province') {
            return group.state.country || findRegion(group, 'country', text(region.country_code)) || null;
        }
        return null;
    }

    function deeperSearchLevels(level) {
        var index = order.indexOf(level);
        if (index < 0) {
            return [];
        }
        return order.slice(index + 1);
    }

    function searchHits(group, control, keyword) {
        var needle = text(keyword).trim().toLowerCase();
        var level = control.level;
        var seen = {};
        var hits = [];

        function pushHit(region, hitLevel) {
            if (!region) {
                return;
            }
            var key = text(region.region_type) + ':' + text(region.region_id || region.region_code || labelOf(region));
            if (seen[key]) {
                return;
            }
            seen[key] = true;
            hits.push({region: region, level: hitLevel});
        }

        optionsFor(group, level).forEach(function (region) {
            if (regionMatchesKeyword(region, needle)) {
                pushHit(region, level);
            }
        });

        if (needle) {
            deeperSearchLevels(level).forEach(function (deeperLevel) {
                optionsFor(group, deeperLevel).forEach(function (region) {
                    if (regionMatchesKeyword(region, needle)) {
                        pushHit(region, deeperLevel);
                    }
                });
            });
        }

        return hits.slice(0, 50);
    }

    function pathLabelForHit(group, hit, controlLevel) {
        if (hit.level === controlLevel) {
            return labelOf(hit.region);
        }
        var chain = [];
        var node = hit.region;
        var guard = 0;
        while (node && guard++ < 8) {
            chain.unshift(node);
            node = parentRegion(group, node);
        }
        var start = order.indexOf(controlLevel);
        var names = chain.filter(function (region) {
            return order.indexOf(text(region.region_type)) >= start;
        }).map(labelOf);
        return names.length ? names.join(' / ') : labelOf(hit.region);
    }

    function applySearchHit(group, control, hit) {
        var chain = [];
        var node = hit.region;
        var guard = 0;
        while (node && guard++ < 8) {
            chain.unshift(node);
            node = parentRegion(group, node);
        }
        chain.forEach(function (region) {
            var type = text(region.region_type);
            if (order.indexOf(type) < 0) {
                return;
            }
            group.state[type] = region;
            if (group.controls[type]) {
                group.controls[type].field.value = labelOf(region);
            }
        });
        clearAfter(group, hit.level);
        updateGroup(group);
        closeMenus(group);
        var emitControl = group.controls[hit.level] || control;
        emitControl.field.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function renderMenu(group, control, keyword) {
        var labels = group.labels || defaultLabels;
        var needle = text(keyword).trim().toLowerCase();
        var hits = searchHits(group, control, keyword);
        if (!hits.length) {
            control.menu.innerHTML = '<div class="w-address__empty">' + escapeHtml(canUseManualInput(group, control) && needle ? labels.manual : labels.empty) + '</div>';
            return;
        }
        control.menu.innerHTML = hits.map(function (hit, index) {
            return '<button type="button" class="w-address__option" data-index="' + index + '">' + escapeHtml(pathLabelForHit(group, hit, control.level)) + '</button>';
        }).join('');
        control.menu.querySelectorAll('.w-address__option').forEach(function (button) {
            button.addEventListener('click', function () {
                var hit = hits[Number(button.dataset.index)];
                if (!hit) {
                    return;
                }
                applySearchHit(group, control, hit);
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
        if (current) {
            control.field.value = labelOf(current);
        }
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
        group.sourceUrl = frontendRoute(config.sourceUrl || group.sourceUrl || defaultSourceUrl);
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
            group.regions = regions || [];
            // 异步加载会覆盖本地合成国家；按已锁定国家重新注入。
            ensureCountryInRegions(
                group,
                group.fixed.country || metadataValue(group, 'country_code'),
                metadataValue(group, 'country')
            );
            updateGroup(group);
        });
    }

    function applyValues(codeOrRoot, values) {
        values = values || {};
        var group = null;
        if (typeof codeOrRoot === 'string') {
            group = groups[codeOrRoot];
        } else if (codeOrRoot && codeOrRoot.dataset && codeOrRoot.dataset.addressCode) {
            group = groups[codeOrRoot.dataset.addressCode];
        } else if (codeOrRoot && codeOrRoot.getAttribute) {
            var rootNode = codeOrRoot.closest ? codeOrRoot.closest('[data-w-address]') : null;
            if (rootNode && rootNode.dataset.addressCode) {
                group = groups[rootNode.dataset.addressCode];
            }
        }
        if (!group) {
            boot();
            if (typeof codeOrRoot === 'string') {
                group = groups[codeOrRoot];
            }
        }
        if (!group) {
            return Promise.resolve(false);
        }

        var root = null;
        Object.keys(group.controls).some(function (level) {
            root = group.controls[level].root;
            return !!root;
        });
        var form = root ? root.closest('form') : null;
        var countryCode = text(values.country_code || values.countryCode || '').toUpperCase();
        var countryName = text(values.country || '').trim();
        var hasLowerValues = !!(text(values.province || values.region || '').trim()
            || text(values.city || '').trim()
            || text(values.district || '').trim());
        if (countryCode && root) {
            findOrCreateField(root, form, 'country_code').value = countryCode;
            // 无国家控件时（宿主顶部已选国家），锁定国家并写入隐藏字段供提交。
            group.fixed.country = countryCode;
            findOrCreateField(root, form, 'country').value = countryName || countryCode;
            // 地区库可能没有该国节点（如澳门/香港），预先放入合成国家，避免 refreshState 回落 CN。
            ensureCountryInRegions(group, countryCode, countryName || countryCode);
        }

        function setLevel(level, value) {
            value = text(value).trim();
            if (!group.controls[level] || !group.controls[level].field) {
                return;
            }
            // 允许显式传空字符串以清空下级；无值则跳过（保留原值）。
            if (!value && values[level] === undefined && !(level === 'province' && values.region !== undefined)) {
                return;
            }
            group.controls[level].field.value = value;
        }

        setLevel('country', countryName || countryCode);
        if (countryCode && !hasLowerValues) {
            ['province', 'city', 'district'].forEach(function (level) {
                if (group.controls[level] && group.controls[level].field) {
                    group.controls[level].field.value = '';
                }
            });
        }
        setLevel('province', values.province || values.region);
        setLevel('city', values.city);
        setLevel('district', values.district);

        function finish() {
            refreshState(group);
            // 无省市区数据时保持可手填；占位符在 updateGroup 中按 fixed.country 更新。
            syncMetadata(group);
            updateGroup(group);
            return true;
        }

        // 切国家时带 country_code 重新拉列表，触发服务端 ensure 入库后再渲染级联。
        if (countryCode) {
            return loadRegions(group.sourceUrl, countryCode).then(function (regions) {
                group.regions = regions || [];
                ensureCountryInRegions(group, countryCode, countryName || countryCode);
                return finish();
            });
        }

        if (group.regions && group.regions.length) {
            return Promise.resolve(finish());
        }

        return loadRegions(group.sourceUrl).then(function (regions) {
            group.regions = regions || [];
            return finish();
        });
    }

    function boot() {
        document.querySelectorAll('[data-w-address]').forEach(mount);
    }

    window.WelineThemeAddress = {boot: boot, groups: groups, applyValues: applyValues};
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
