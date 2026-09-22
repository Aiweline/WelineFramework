(function () {
    'use strict';

    // Always redefine export so cache-busted reloads pick up postal/street APIs.
    // mount() is idempotent via data-w-address-ready.

    var order = ['country', 'province', 'city', 'district', 'street'];
    var autoCode = 0;
    var groups = (window.WelineThemeAddress && window.WelineThemeAddress.groups) || {};
    var regionSources = (window.WelineThemeAddress && window.WelineThemeAddress.__regionSources) || {};
    var streetSources = (window.WelineThemeAddress && window.WelineThemeAddress.__streetSources) || {};
    var regionApiPromise = null;
    var defaultSourceUrl = frontendRoute('/shipping/frontend/region/list');
    var defaultLabels = {
        country: '\u56fd\u5bb6/\u5730\u533a',
        province: '\u7701\u4efd',
        city: '\u57ce\u5e02',
        district: '\u533a\u53bf',
        street: '\u8857\u9053',
        empty: '\u6682\u65e0\u53ef\u9009\u5730\u533a',
        manual: '\u53ef\u76f4\u63a5\u8f93\u5165\u8be5\u5730\u533a',
        selectCountry: '\u8bf7\u9009\u62e9\u56fd\u5bb6/\u5730\u533a',
        selectPostalCountry: '\u8be5\u90ae\u7f16\u5339\u914d\u591a\u4e2a\u56fd\u5bb6\uff0c\u8bf7\u9009\u62e9',
        postalCountryGroup: '\u90ae\u7f16\u5339\u914d',
        otherCountryGroup: '\u5176\u5b83\u56fd\u5bb6/\u5730\u533a',
        unsupportedCountry: '\u672c\u7ad9\u4e0d\u652f\u6301',
        embargoedRegion: '\u4e0d\u652f\u6301\u914d\u9001',
        selectProvince: '\u8bf7\u9009\u62e9\u7701\u4efd',
        selectCity: '\u8bf7\u9009\u62e9\u57ce\u5e02',
        selectDistrict: '\u8bf7\u9009\u62e9\u533a\u53bf',
        selectStreet: '\u8bf7\u9009\u62e9\u8857\u9053',
        enterStreet: '\u8bf7\u586b\u5199\u8be6\u7ec6\u5730\u5740',
        selectCountryFirst: '\u8bf7\u5148\u9009\u62e9\u56fd\u5bb6/\u5730\u533a',
        selectProvinceFirst: '\u8bf7\u5148\u9009\u62e9\u7701\u4efd',
        selectCityFirst: '\u8bf7\u5148\u9009\u62e9\u57ce\u5e02',
        selectDistrictFirst: '\u8bf7\u5148\u9009\u62e9\u533a\u53bf',
        loading: '\u52a0\u8f7d\u4e2d\u2026',
        multiHint: '\u5c1a\u672a\u9009\u62e9\uff0c\u8bf7\u641c\u7d22\u540e\u6dfb\u52a0',
        searchCountry: '\u641c\u7d22\u5e76\u6dfb\u52a0\u56fd\u5bb6/\u5730\u533a',
        searchProvince: '\u641c\u7d22\u5e76\u6dfb\u52a0\u7701\u4efd',
        searchCity: '\u641c\u7d22\u5e76\u6dfb\u52a0\u57ce\u5e02',
        searchDistrict: '\u641c\u7d22\u5e76\u6dfb\u52a0\u533a\u53bf',
        searchStreet: '\u641c\u7d22\u5e76\u6dfb\u52a0\u8857\u9053',
        typeToFilter: '\u8f93\u5165\u5173\u952e\u5b57\u7b5b\u9009\u66f4\u591a',
        poolCountHint: '\u5171 {n} \u9879\u53ef\u9009\uff0c\u8f93\u5165\u5173\u952e\u5b57\u7b5b\u9009',
        noChildren: '\u5730\u5740\u5e93\u672a\u6536\u5f55\u4e0b\u7ea7\u533a\u5212',
        continentPopular: '\u70ed\u95e8',
        continentAsia: '\u4e9a\u6d32',
        continentEurope: '\u6b27\u6d32',
        continentNorthAmerica: '\u5317\u7f8e\u6d32',
        continentSouthAmerica: '\u5357\u7f8e\u6d32',
        continentOceania: '\u5927\u6d0b\u6d32',
        continentAfrica: '\u975e\u6d32',
        continentOther: '\u5176\u4ed6'
    };
    var englishLabels = {
        country: 'Country/Region',
        province: 'Province',
        city: 'City',
        district: 'District',
        street: 'Street',
        empty: 'No regions available',
        manual: 'You can enter this region directly',
        selectCountry: 'Please select country/region',
        selectPostalCountry: 'This postal matches multiple countries — please choose',
        postalCountryGroup: 'Postal matches',
        otherCountryGroup: 'Other countries/regions',
        unsupportedCountry: 'Not supported by this store',
        embargoedRegion: 'Delivery not supported',
        selectProvince: 'Please select province',
        selectCity: 'Please select city',
        selectDistrict: 'Please select district',
        selectStreet: 'Please select street',
        enterStreet: 'Enter street address',
        selectCountryFirst: 'Please select country/region first',
        selectProvinceFirst: 'Please select province first',
        selectCityFirst: 'Please select city first',
        selectDistrictFirst: 'Please select district first',
        loading: 'Loading\u2026',
        multiHint: 'Nothing selected yet — search to add',
        searchCountry: 'Search and add country/region',
        searchProvince: 'Search and add province',
        searchCity: 'Search and add city',
        searchDistrict: 'Search and add district',
        searchStreet: 'Search and add street',
        typeToFilter: 'Type to filter more results',
        poolCountHint: '{n} options available — type to filter',
        noChildren: 'Not in address catalog (no subdivisions packed)',
        continentPopular: 'Popular',
        continentAsia: 'Asia',
        continentEurope: 'Europe',
        continentNorthAmerica: 'North America',
        continentSouthAmerica: 'South America',
        continentOceania: 'Oceania',
        continentAfrica: 'Africa',
        continentOther: 'Other'
    };
    // E-commerce continent buckets (ISO 3166-1 alpha-2). popular is UX-only and may overlap continents.
    var commerceContinentOrder = ['popular', 'asia', 'europe', 'north_america', 'south_america', 'oceania', 'africa', 'other'];
    var commerceContinentCodes = {
        // Align with Shipping default-markets sort_order (hot first).
        popular: ['CN', 'HK', 'MO', 'TW', 'US', 'CA', 'GB', 'DE', 'JP', 'AU', 'FR', 'KR', 'SG', 'MY', 'TH', 'VN', 'ID', 'PH', 'IN', 'MX', 'BR', 'IT', 'ES', 'NL', 'NZ', 'AE', 'RU'],
        asia: ['AE', 'AF', 'AM', 'AZ', 'BD', 'BH', 'BN', 'BT', 'CN', 'CY', 'GE', 'HK', 'ID', 'IL', 'IN', 'IO', 'IQ', 'IR', 'JO', 'JP', 'KG', 'KH', 'KP', 'KR', 'KW', 'KZ', 'LA', 'LB', 'LK', 'MM', 'MN', 'MO', 'MV', 'MY', 'NP', 'OM', 'PH', 'PK', 'PS', 'QA', 'SA', 'SG', 'SY', 'TH', 'TJ', 'TL', 'TM', 'TR', 'TW', 'UZ', 'VN', 'YE'],
        europe: ['AD', 'AL', 'AT', 'AX', 'BA', 'BE', 'BG', 'BY', 'CH', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FO', 'FR', 'GB', 'GG', 'GI', 'GR', 'HR', 'HU', 'IE', 'IM', 'IS', 'IT', 'JE', 'LI', 'LT', 'LU', 'LV', 'MC', 'MD', 'ME', 'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'RU', 'SE', 'SI', 'SJ', 'SK', 'SM', 'UA', 'VA'],
        north_america: ['AG', 'AI', 'AW', 'BB', 'BL', 'BM', 'BQ', 'BS', 'BZ', 'CA', 'CR', 'CU', 'CW', 'DM', 'DO', 'GD', 'GL', 'GP', 'GT', 'HN', 'HT', 'JM', 'KN', 'KY', 'LC', 'MF', 'MQ', 'MS', 'MX', 'NI', 'PA', 'PM', 'PR', 'SV', 'SX', 'TC', 'TT', 'UM', 'US', 'VC', 'VG', 'VI'],
        south_america: ['AR', 'BO', 'BR', 'CL', 'CO', 'EC', 'FK', 'GF', 'GS', 'GY', 'PE', 'PY', 'SR', 'UY', 'VE'],
        oceania: ['AS', 'AU', 'CC', 'CK', 'CX', 'FJ', 'FM', 'GU', 'HM', 'KI', 'MH', 'MP', 'NC', 'NF', 'NR', 'NU', 'NZ', 'PF', 'PG', 'PN', 'PW', 'SB', 'TK', 'TO', 'TV', 'VU', 'WF', 'WS'],
        africa: ['AO', 'BF', 'BI', 'BJ', 'BW', 'CD', 'CF', 'CG', 'CI', 'CM', 'CV', 'DJ', 'DZ', 'EG', 'EH', 'ER', 'ET', 'GA', 'GH', 'GM', 'GN', 'GQ', 'GW', 'KE', 'KM', 'LR', 'LS', 'LY', 'MA', 'MG', 'ML', 'MR', 'MU', 'MW', 'MZ', 'NA', 'NE', 'NG', 'RE', 'RW', 'SC', 'SD', 'SH', 'SL', 'SN', 'SO', 'SS', 'ST', 'SZ', 'TD', 'TG', 'TN', 'TZ', 'UG', 'YT', 'ZA', 'ZM', 'ZW'],
        other: ['AQ', 'BV', 'TF']
    };
    var commerceContinentIndex = null;

    function buildCommerceContinentIndex() {
        if (commerceContinentIndex) {
            return commerceContinentIndex;
        }
        commerceContinentIndex = {};
        commerceContinentOrder.forEach(function (key) {
            if (key === 'popular') {
                return;
            }
            (commerceContinentCodes[key] || []).forEach(function (cc) {
                commerceContinentIndex[String(cc).toUpperCase()] = key;
            });
        });
        return commerceContinentIndex;
    }

    function commerceContinentKey(countryCode) {
        var cc = text(countryCode).toUpperCase();
        if (!cc) {
            return 'other';
        }
        return buildCommerceContinentIndex()[cc] || 'other';
    }

    function isPopularCountry(countryCode) {
        var cc = text(countryCode).toUpperCase();
        return (commerceContinentCodes.popular || []).indexOf(cc) > -1;
    }

    function commerceContinentLabel(key, labels) {
        labels = labels || defaultLabels;
        var table = {
            popular: labels.continentPopular,
            asia: labels.continentAsia,
            europe: labels.continentEurope,
            north_america: labels.continentNorthAmerica,
            south_america: labels.continentSouthAmerica,
            oceania: labels.continentOceania,
            africa: labels.continentAfrica,
            other: labels.continentOther
        };
        return text(table[key] || table.other || key);
    }

    function countryCodeFromMenuHit(hit) {
        if (!hit) {
            return '';
        }
        if (hit.region) {
            return text(hit.region.country_code || hit.region.region_code).toUpperCase();
        }
        if (hit.formatted_address) {
            return text(hit.formatted_address.country_code).toUpperCase();
        }
        return text(hit.country_code || hit.region_code).toUpperCase();
    }

    function countryDisplayNameFromEntry(entry) {
        var hit = entry && entry.hit ? entry.hit : entry;
        if (!hit) {
            return '';
        }
        if (hit.region) {
            return text(hit.label || hit.region.region_name || hit.region.region_code || '');
        }
        return text(hit.label || hit.region_name || hit.region_code || hit.country_code || '');
    }

    function regionHotSortOrder(regionOrHit) {
        var region = regionOrHit;
        if (regionOrHit && regionOrHit.hit) {
            region = regionOrHit.hit.region || regionOrHit.hit;
        } else if (regionOrHit && regionOrHit.region) {
            region = regionOrHit.region;
        }
        if (!region) {
            return 9000;
        }
        var raw = region.sort_order;
        if (raw === undefined || raw === null || raw === '') {
            return 9000;
        }
        var n = Number(raw);
        return isFinite(n) ? Math.max(0, n) : 9000;
    }

    function compareCountryEntriesByHotSort(a, b) {
        var sa = regionHotSortOrder(a);
        var sb = regionHotSortOrder(b);
        if (sa !== sb) {
            return sa - sb;
        }
        return countryDisplayNameFromEntry(a).localeCompare(countryDisplayNameFromEntry(b), 'zh');
    }

    /**
     * Group country menu entries by commerce continent.
     * When includePopular=true (empty browse), show 热门 first and omit those codes from later continents.
     */
    function groupCountryEntriesByContinent(entries, labels, options) {
        options = options || {};
        var includePopular = !!options.includePopular;
        var buckets = {};
        commerceContinentOrder.forEach(function (key) {
            buckets[key] = [];
        });
        var popularSeen = {};
        if (includePopular) {
            var popularRank = {};
            (commerceContinentCodes.popular || []).forEach(function (cc, idx) {
                popularRank[cc] = idx;
            });
            (entries || []).forEach(function (entry) {
                var cc = countryCodeFromMenuHit(entry.hit || entry);
                if (!isPopularCountry(cc)) {
                    return;
                }
                buckets.popular.push(entry);
                popularSeen[cc] = true;
            });
            buckets.popular.sort(function (a, b) {
                var sa = regionHotSortOrder(a);
                var sb = regionHotSortOrder(b);
                if (sa !== sb) {
                    return sa - sb;
                }
                var ca = countryCodeFromMenuHit(a.hit || a);
                var cb = countryCodeFromMenuHit(b.hit || b);
                var ra = Object.prototype.hasOwnProperty.call(popularRank, ca) ? popularRank[ca] : 999;
                var rb = Object.prototype.hasOwnProperty.call(popularRank, cb) ? popularRank[cb] : 999;
                if (ra !== rb) {
                    return ra - rb;
                }
                return compareCountryEntriesByHotSort(a, b);
            });
        }
        (entries || []).forEach(function (entry) {
            var cc = countryCodeFromMenuHit(entry.hit || entry);
            if (includePopular && popularSeen[cc]) {
                return;
            }
            var key = commerceContinentKey(cc);
            buckets[key].push(entry);
        });
        commerceContinentOrder.forEach(function (key) {
            if (key === 'popular' || !buckets[key].length) {
                return;
            }
            buckets[key].sort(compareCountryEntriesByHotSort);
        });
        var out = [];
        commerceContinentOrder.forEach(function (key) {
            if (!buckets[key].length) {
                return;
            }
            out.push({
                label: commerceContinentLabel(key, labels),
                entries: buckets[key]
            });
        });
        return out;
    }

    function assignGroupAnchors(sections) {
        var anchor = 0;
        return (sections || []).map(function (section) {
            var next = Object.assign({}, section);
            if (text(next.label)) {
                next.anchorId = String(anchor++);
            }
            return next;
        });
    }

    function buildGroupJumpHtml(sections) {
        var chips = [];
        (sections || []).forEach(function (section) {
            if (!text(section.label) || section.anchorId == null) {
                return;
            }
            chips.push(section);
        });
        if (chips.length < 2) {
            return '';
        }
        return '<div class="w-address__group-jump" role="tablist" aria-label="group-jump">'
            + chips.map(function (section, index) {
                return '<button type="button" class="w-address__group-jump-chip' + (index === 0 ? ' is-active' : '') + '" data-group-jump="'
                    + escapeHtml(String(section.anchorId)) + '" role="tab">'
                    + escapeHtml(section.label) + '</button>';
            }).join('')
            + '</div>';
    }

    /**
     * sticky group-label 会让 scrollIntoView 误判「已在视口」，回点第一组不滚动。
     * 用 sticky 跳转条高度 + getBoundingClientRect 手动设 scrollTop，支持来回切换。
     */
    function scrollMenuToGroup(menu, target) {
        if (!menu || !target) {
            return;
        }
        var jump = menu.querySelector('.w-address__group-jump');
        var jumpH = jump ? jump.offsetHeight : 0;
        var menuRect = menu.getBoundingClientRect();
        var targetRect = target.getBoundingClientRect();
        var next = menu.scrollTop + (targetRect.top - menuRect.top) - jumpH;
        menu.scrollTop = Math.max(0, next);
    }

    function bindGroupJumpNavigation(menu) {
        if (!menu) {
            return;
        }
        menu.classList.toggle('has-group-jump', !!menu.querySelector('.w-address__group-jump'));
        var chips = menu.querySelectorAll('[data-group-jump]');
        if (!chips.length) {
            return;
        }
        chips.forEach(function (chip) {
            chip.addEventListener('mousedown', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var id = chip.getAttribute('data-group-jump');
                var target = menu.querySelector('[data-group-anchor="' + id + '"]');
                scrollMenuToGroup(menu, target);
                chips.forEach(function (node) {
                    node.classList.toggle('is-active', node === chip);
                });
            });
        });
    }

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
            var attrCatalog = text(root.getAttribute('data-catalog') || '');
            if (attrCatalog && !text(config.catalog || '')) {
                config.catalog = attrCatalog;
            }
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
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return Promise.reject(new Error('Weline.Api unavailable'));
        }
        if (!regionApiPromise) {
            regionApiPromise = Promise.resolve(window.Weline.Api.resource('region'));
        }
        return regionApiPromise;
    }

    /** 地区业务请求默认走 BinQuery（Weline.Api.resource('region')）；禁止 HTTP 主路径、禁止把 BinQuery 当回退。 */
    function callRegion(opName, params) {
        return getRegionApi().then(function (RegionApi) {
            if (!RegionApi || typeof RegionApi[opName] !== 'function') {
                return Promise.reject(new Error('region.' + opName + ' unavailable'));
            }
            return RegionApi[opName](params || {}, {silent: true});
        });
    }

    function buildSourceRequestUrl(sourceUrl, countryCode, catalog) {
        var url = text(sourceUrl || defaultSourceUrl);
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        if (text(catalog) === 'global') {
            url += sep + 'catalog=global';
            sep = '&';
        }
        if (text(countryCode)) {
            url += sep + 'country_code=' + encodeURIComponent(text(countryCode).toUpperCase());
        }
        return url;
    }

    function fetchRegionsFromSource(sourceUrl, countryCode, catalog) {
        var params = {catalog: text(catalog) === 'global' ? 'global' : 'installed'};
        if (text(countryCode)) {
            params.country_code = text(countryCode).toUpperCase();
        }
        return callRegion('list', params).then(function (payload) {
            return normalizeRegions(regionsFromPayload(payload));
        });
    }

    function suggestRegions(query, countryCode, limit) {
        query = text(query).trim();
        if (!query) {
            return Promise.resolve([]);
        }
        function normalizeSuggestPayload(payload) {
            var data = payload && payload.data !== undefined ? payload.data : payload;
            return Array.isArray(data) ? data : [];
        }
        if (window.Weline && window.Weline.Api) {
            return getRegionApi().then(function (RegionApi) {
                if (RegionApi && typeof RegionApi.suggest === 'function') {
                    var params = {q: query, limit: limit || 8};
                    if (text(countryCode)) {
                        params.country_code = text(countryCode).toUpperCase();
                    }
                    return RegionApi.suggest(params, {silent: true}).then(normalizeSuggestPayload);
                }
                return null;
            }).then(function (rows) {
                return Array.isArray(rows) ? rows : [];
            }).catch(function () {
                return [];
            });
        }
        return Promise.resolve([]);
    }

    function postalLookup(countryCode, postalCode, limit) {
        countryCode = text(countryCode).toUpperCase();
        postalCode = text(postalCode).trim();
        if (!countryCode || !postalCode) {
            return Promise.resolve([]);
        }
        function normalizePostalPayload(payload) {
            var data = payload && payload.data !== undefined ? payload.data : payload;
            return Array.isArray(data) ? data : [];
        }
        if (!window.Weline || !window.Weline.Api) {
            return Promise.resolve([]);
        }
        return callRegion('postal_lookup', {
            country_code: countryCode,
            postal_code: postalCode,
            limit: limit || 20
        }).then(normalizePostalPayload).catch(function () {
            return [];
        });
    }

    function postalCountries(postalCode) {
        postalCode = text(postalCode).trim();
        if (!postalCode) {
            return Promise.resolve([]);
        }
        function normalizeCountriesPayload(payload) {
            var data = payload && payload.data !== undefined ? payload.data : payload;
            if (!Array.isArray(data)) {
                return [];
            }
            return data.map(function (row) {
                if (typeof row === 'string') {
                    return {country_code: text(row).toUpperCase(), country_name: text(row).toUpperCase(), supported: true, embargoed: false, place_name: ''};
                }
                return {
                    country_code: text(row.country_code || row.code || '').toUpperCase(),
                    country_name: text(row.country_name || row.name || row.country_code || row.code || ''),
                    place_name: text(row.place_name || ''),
                    supported: row.supported === undefined ? true : !!row.supported,
                    embargoed: !!row.embargoed
                };
            }).filter(function (row) {
                return row.country_code.length === 2;
            });
        }
        var rowsPromise = (!window.Weline || !window.Weline.Api)
            ? Promise.resolve([])
            : callRegion('postal_countries', {postal_code: postalCode}).then(normalizeCountriesPayload).catch(function () {
                return [];
            });
        return rowsPromise.then(function (rows) {
            return embargoedCountryCodes().then(function (blocked) {
                return (rows || []).map(function (row) {
                    // API 可按邮编命中地点标省级/区级禁运；国家级名单再并入
                    if (row.embargoed || blocked[row.country_code]) {
                        row.supported = false;
                        row.embargoed = true;
                    }
                    return row;
                });
            });
        });
    }


    // Survives cache-busted address.js reloads (same page lifetime).
    var countryEmbargoCache = (window.WelineThemeAddress && window.WelineThemeAddress.__countryEmbargoCache) || null;
    var subnationalEmbargoCache = (window.WelineThemeAddress && window.WelineThemeAddress.__subnationalEmbargoCache) || {};

    function embargoedCountryCodes() {
        var globalStore = window.__welineAddressEmbargoStore || (window.__welineAddressEmbargoStore = {});
        if (globalStore.countriesReq || countryEmbargoCache) {
            return globalStore.countriesReq || countryEmbargoCache;
        }
        if (!window.Weline || !window.Weline.Api) {
            return Promise.resolve({});
        }
        var req = callRegion('embargo_countries', {}).then(function (payload) {
            var data = payload && payload.data !== undefined ? payload.data : payload;
            var map = {};
            (Array.isArray(data) ? data : []).forEach(function (code) {
                code = text(code).toUpperCase();
                if (code.length === 2) {
                    map[code] = true;
                }
            });
            return map;
        }).catch(function () {
            // Keep empty map cached — do not re-arm stampede on transient failure.
            return {};
        });
        countryEmbargoCache = req;
        globalStore.countriesReq = req;
        if (window.WelineThemeAddress) {
            window.WelineThemeAddress.__countryEmbargoCache = req;
        }
        return req;
    }

    function evaluateEmbargo(address) {
        address = address || {};
        if (!window.Weline || !window.Weline.Api) {
            return Promise.resolve({blocked: false});
        }
        return callRegion('embargo_evaluate', {
            country_code: text(address.country_code || address.country || '').toUpperCase(),
            province_code: text(address.province_code || address.province || ''),
            province_region_id: Number(address.province_region_id || 0),
            city_code: text(address.city_code || address.city || ''),
            city_region_id: Number(address.city_region_id || 0),
            district_code: text(address.district_code || address.district || ''),
            district_region_id: Number(address.district_region_id || 0),
            street_code: text(address.street_code || address.street || ''),
            street_id: Number(address.street_id || 0)
        }).then(function (payload) {
            var data = payload && payload.data !== undefined ? payload.data : payload;
            return data && typeof data === 'object' ? data : {blocked: false};
        }).catch(function () {
            return {blocked: false};
        });
    }

    function buildAddressPayloadFromGroup(group) {
        var country = group.state.country;
        var province = group.state.province;
        var city = group.state.city;
        var district = group.state.district;
        var street = group.state.street;
        return {
            country_code: country ? text(country.country_code || country.region_code).toUpperCase() : text(group.fixed.country || '').toUpperCase(),
            province_code: province ? text(province.region_code) : '',
            province_region_id: province ? Number(province.region_id || 0) : 0,
            city_code: city ? text(city.region_code) : '',
            city_region_id: city ? Number(city.region_id || 0) : 0,
            district_code: district ? text(district.region_code) : '',
            district_region_id: district ? Number(district.region_id || 0) : 0,
            street_code: street ? text(street.region_code) : '',
            street_id: street ? Number(street.street_id || street.region_id || 0) : 0
        };
    }

    function refreshEmbargoState(group) {
        if (!group) {
            return Promise.resolve({blocked: false});
        }
        var token = String(Date.now()) + '-embargo';
        group.embargoToken = token;
        return evaluateEmbargo(buildAddressPayloadFromGroup(group)).then(function (result) {
            if (group.embargoToken !== token) {
                return result;
            }
            group.embargo = result && typeof result === 'object' ? result : {blocked: false};
            Object.keys(group.controls || {}).forEach(function (level) {
                updateControl(group, group.controls[level]);
            });
            return group.embargo;
        });
    }

    function resolveAddressGroup(codeOrRoot) {
        if (typeof codeOrRoot === 'string') {
            return groups[codeOrRoot] || null;
        }
        if (codeOrRoot && codeOrRoot.dataset && codeOrRoot.dataset.addressCode) {
            return groups[codeOrRoot.dataset.addressCode] || null;
        }
        return null;
    }

    function clearPostalCountryPrompt(group) {
        if (!group) {
            return;
        }
        group.postalCountryFilter = null;
        group.pendingPostalCode = '';
        if (Object.prototype.hasOwnProperty.call(group, '_postalSavedFixedCountry')) {
            // 未选国时不强制写回旧固定国；选国后由 applyPostalCandidate 设置 fixed.country
            delete group._postalSavedFixedCountry;
        }
    }

    function collectPostalCountryCodes(countries) {
        var codes = [];
        var seen = {};
        (Array.isArray(countries) ? countries : []).forEach(function (row) {
            var code = text(typeof row === 'string' ? row : (row.country_code || row.code || '')).toUpperCase();
            if (code.length !== 2 || seen[code]) {
                return;
            }
            seen[code] = true;
            codes.push(code);
        });
        return codes;
    }

    function setPostalCountryPin(codeOrRoot, countries, postal) {
        var group = resolveAddressGroup(codeOrRoot);
        postal = text(postal).trim();
        if (!group) {
            return false;
        }
        var list = Array.isArray(countries) ? countries : [];
        var codes = collectPostalCountryCodes(list);
        list.forEach(function (row) {
            var code = text(typeof row === 'string' ? row : (row.country_code || row.code || '')).toUpperCase();
            var name = text(typeof row === 'string' ? row : (row.country_name || row.name || code));
            var placeName = (typeof row === 'object' && row) ? text(row.place_name || '') : '';
            if (code.length !== 2) {
                return;
            }
            if (placeName && name.indexOf(placeName) < 0) {
                name = name + ' · ' + placeName;
            }
            var supported = true;
            if (typeof row === 'object' && row && row.supported !== undefined) {
                supported = !!row.supported;
            }
            ensureCountryInRegions(group, code, name || code);
            var region = findRegion(group, 'country', code)
                || (group.regions || []).find(function (item) {
                    return text(item.region_type) === 'country'
                        && (text(item.country_code) === code || text(item.region_code) === code);
                });
            if (region) {
                region.postal_unsupported = !supported;
                if (typeof row === 'object' && row && row.embargoed) {
                    region.embargoed = true;
                    region.postal_unsupported = true;
                }
                if (name) {
                    region.region_name = name;
                }
            }
        });
        if (!codes.length) {
            return false;
        }
        group.postalCountryFilter = codes;
        if (postal) {
            group.pendingPostalCode = postal;
        }
        return true;
    }

    function prioritizePostalCountryHits(group, control, hits) {
        if (!group || !control || control.level !== 'country') {
            return hits || [];
        }
        var filter = Array.isArray(group.postalCountryFilter) ? group.postalCountryFilter : [];
        if (!filter.length) {
            return hits || [];
        }
        var pinIndex = {};
        filter.forEach(function (code, idx) {
            pinIndex[text(code).toUpperCase()] = idx;
        });
        var pinned = [];
        var rest = [];
        (hits || []).forEach(function (hit) {
            var cc = '';
            if (hit && hit.region) {
                cc = text(hit.region.country_code || hit.region.region_code).toUpperCase();
            }
            if (!cc && hit && hit.formatted_address) {
                cc = text(hit.formatted_address.country_code).toUpperCase();
            }
            if (Object.prototype.hasOwnProperty.call(pinIndex, cc)) {
                pinned.push(hit);
            } else {
                rest.push(hit);
            }
        });
        pinned.sort(function (a, b) {
            var ca = text((a.region && (a.region.country_code || a.region.region_code)) || (a.formatted_address && a.formatted_address.country_code) || '').toUpperCase();
            var cb = text((b.region && (b.region.country_code || b.region.region_code)) || (b.formatted_address && b.formatted_address.country_code) || '').toUpperCase();
            return (pinIndex[ca] || 0) - (pinIndex[cb] || 0);
        });
        return pinned.concat(rest);
    }

    function promptPostalCountries(codeOrRoot, countries, postal) {
        var group = resolveAddressGroup(codeOrRoot);
        postal = text(postal).trim();
        if (!group || !postal) {
            return Promise.resolve(false);
        }
        var list = Array.isArray(countries) ? countries : [];
        var labels = group.labels || defaultLabels;
        if (!setPostalCountryPin(group.code || codeOrRoot, list, postal)) {
            return Promise.resolve(false);
        }
        var codes = group.postalCountryFilter || [];
        if (!codes.length || !group.controls.country) {
            return Promise.resolve(false);
        }
        if (!Object.prototype.hasOwnProperty.call(group, '_postalSavedFixedCountry')) {
            group._postalSavedFixedCountry = group.fixed.country || '';
        }
        // 多国待选时解除固定国（否则会一直显示「中国」）
        group.fixed.country = '';
        ['province', 'city', 'district', 'street'].forEach(function (level) {
            group.state[level] = null;
            if (group.controls[level]) {
                group.controls[level].field.value = '';
                group.controls[level].input.value = '';
            }
        });
        group.state.country = null;
        group.controls.country.field.value = '';
        (function clearCountryMeta() {
            var rootNode = group.controls.country.root;
            var form = rootNode ? rootNode.closest('form') : null;
            if (!form) { return; }
            ['country_code', 'country'].forEach(function (name) {
                var input = form.querySelector('[name="' + name + '"]');
                if (input) { input.value = ''; }
            });
        })();
        group.controls.country.input.value = '';
        group.controls.country.input.placeholder = text(labels.selectPostalCountry || labels.selectCountry);
        syncMetadata(group);
        updateGroup(group);
        group.controls.country.field.value = '';
        group.controls.country.input.value = '';
        group.controls.country.input.placeholder = text(labels.selectPostalCountry || labels.selectCountry);
        closeMenus(group);
        var control = group.controls.country;
        control.item.classList.add('is-open');
        control.menu.hidden = false;
        return ensureLevelChildren(group, 'country').then(function () {
            renderMenu(group, control, '');
            placeSingleMenu(control);
            try {
                control.input.focus();
            } catch (e) {}
            return true;
        });
    }

    function streetParentId(group) {
        if (!group) {
            return 0;
        }
        var district = group.state && group.state.district;
        var city = group.state && group.state.city;
        var parent = district || city || null;
        return parent ? Number(parent.region_id || 0) : 0;
    }

    function streetsAsRegions(rows, parentRegionId, countryCode) {
        return (rows || []).map(function (row) {
            return {
                region_id: Number(row.street_id || row.region_id || 0),
                street_id: Number(row.street_id || row.region_id || 0),
                parent_region_id: Number(row.parent_region_id || parentRegionId || 0),
                country_code: text(row.country_code || countryCode),
                region_code: text(row.street_code || row.region_code || ''),
                region_name: text(row.street_name || row.region_name || ''),
                region_type: 'street',
                postal_code: text(row.postal_code || '')
            };
        }).filter(function (region) {
            return text(region.region_name) !== '';
        });
    }

    function loadStreetsForParent(parentRegionId) {
        parentRegionId = Number(parentRegionId || 0);
        if (parentRegionId <= 0) {
            return Promise.resolve([]);
        }
        var cacheKey = 'streets:' + parentRegionId;
        if (!streetSources[cacheKey]) {
            if (!window.Weline || !window.Weline.Api) {
                streetSources[cacheKey] = Promise.resolve([]);
            } else {
                streetSources[cacheKey] = getRegionApi().then(function (RegionApi) {
                    if (!RegionApi || typeof RegionApi.streets !== 'function') {
                        return [];
                    }
                    return RegionApi.streets({parent_region_id: parentRegionId, limit: 200}, {silent: true});
                }).then(function (payload) {
                    var data = payload && payload.data !== undefined ? payload.data : payload;
                    return Array.isArray(data) ? data : [];
                }).catch(function () {
                    return [];
                });
            }
        }
        return streetSources[cacheKey];
    }

    function probeAndLoadStreets(group) {
        if (!group || !group.controls.street) {
            return Promise.resolve(false);
        }
        var parentId = streetParentId(group);
        group.streetParentId = parentId;
        if (parentId <= 0) {
            group.hasStreets = false;
            group.streetOptions = [];
            return Promise.resolve(false);
        }
        if (!window.Weline || !window.Weline.Api) {
            group.hasStreets = false;
            group.streetOptions = [];
            return Promise.resolve(false);
        }
        return getRegionApi().then(function (RegionApi) {
            if (!RegionApi || typeof RegionApi.has_streets !== 'function') {
                return {has_streets: false};
            }
            return RegionApi.has_streets({parent_region_id: parentId}, {silent: true}).then(function (payload) {
                var data = payload && payload.data !== undefined ? payload.data : payload;
                if (data && typeof data === 'object' && data.has_streets !== undefined) {
                    return data;
                }
                return {has_streets: !!data};
            });
        }).then(function (flag) {
            group.hasStreets = !!(flag && flag.has_streets);
            if (!group.hasStreets) {
                group.streetOptions = [];
                return false;
            }
            return loadStreetsForParent(parentId).then(function (rows) {
                var countryCode = group.state.country
                    ? text(group.state.country.country_code || group.state.country.region_code)
                    : text(group.fixed.country || '');
                group.streetOptions = streetsAsRegions(rows, parentId, countryCode);
                return group.streetOptions.length > 0;
            });
        }).catch(function () {
            group.hasStreets = false;
            group.streetOptions = [];
            return false;
        });
    }

    function embargoCoversControl(embargo, level) {
        if (!embargo || !embargo.blocked) {
            return false;
        }
        var order = ['country', 'province', 'city', 'district', 'street'];
        var blockedLevel = text(embargo.level || 'country');
        var bi = order.indexOf(blockedLevel);
        var ci = order.indexOf(level);
        if (bi < 0 || ci < 0) {
            return blockedLevel === level;
        }
        return ci >= bi;
    }

    function markChildrenInheritedEmbargo(rows, countryCode, blockedMap) {
        var cc = text(countryCode).toUpperCase();
        if (!cc || !blockedMap || !blockedMap[cc]) {
            return rows;
        }
        (Array.isArray(rows) ? rows : []).forEach(function (row) {
            if (row && typeof row === 'object') {
                row.embargoed = true;
            }
        });
        return rows;
    }

    // One in-flight/full-list promise for the whole page — never per-country network fan-out.
    // Use window so duplicate address.js loads (direct script + loader) share one request.
    var SUBNATIONAL_EMBARGO_CACHE_KEY = '*';

    function embargoedSubnationalRules(countryCode) {
        countryCode = text(countryCode).toUpperCase();
        var globalStore = window.__welineAddressEmbargoStore || (window.__welineAddressEmbargoStore = {});
        function filterRows(rows) {
            rows = Array.isArray(rows) ? rows : [];
            if (!countryCode) {
                return rows;
            }
            return rows.filter(function (row) {
                return text(row && row.country_code).toUpperCase() === countryCode;
            });
        }
        var shared = globalStore.regionsReq || subnationalEmbargoCache[SUBNATIONAL_EMBARGO_CACHE_KEY];
        if (shared) {
            return shared.then(filterRows);
        }
        if (!window.Weline || !window.Weline.Api) {
            return Promise.resolve([]);
        }
        // Always request the full active rule set once; filter by country locally.
        // refreshPools() maps over many selected countries — per-country BinQuery stampeded the backend.
        var req = callRegion('embargo_regions', {}).then(function (payload) {
            if (payload && payload.success === false) {
                throw new Error(text(payload.message || 'embargo regions failed'));
            }
            var data = payload && payload.data !== undefined ? payload.data : payload;
            return Array.isArray(data) ? data : [];
        }).catch(function () {
            // Keep resolved empty in cache — deleting here re-arms mount/refreshPools stampede.
            return [];
        });
        globalStore.regionsReq = req;
        subnationalEmbargoCache[SUBNATIONAL_EMBARGO_CACHE_KEY] = req;
        if (window.WelineThemeAddress) {
            window.WelineThemeAddress.__subnationalEmbargoCache = subnationalEmbargoCache;
        }
        return req.then(filterRows);
    }

    function ruleMatchesRegionRow(rule, row, level) {
        if (!rule || !row) {
            return false;
        }
        var ruleType = text(rule.region_type || rule.level || '');
        var rowType = text(level || row.region_type || '');
        if (ruleType && rowType && ruleType !== rowType) {
            return false;
        }
        var ruleId = Number(rule.region_id || 0);
        var rowId = Number(row.region_id || row.id || 0);
        if (ruleId > 0 && rowId > 0 && ruleId === rowId) {
            return true;
        }
        var ruleCode = text(rule.region_code || '').toUpperCase();
        var rowCode = text(row.region_code || row.code || '').toUpperCase();
        return !!(ruleCode && rowCode && ruleCode === rowCode);
    }

    function markChildrenSubnationalEmbargo(rows, countryCode, rules, parentRegion) {
        rows = Array.isArray(rows) ? rows : [];
        rules = Array.isArray(rules) ? rules : [];
        var cc = text(countryCode).toUpperCase();
        if (!cc || !rules.length) {
            return rows;
        }
        var countryRules = rules.filter(function (rule) {
            return text(rule.country_code).toUpperCase() === cc;
        });
        if (!countryRules.length) {
            return rows;
        }
        var parentBlocked = false;
        if (parentRegion) {
            parentBlocked = countryRules.some(function (rule) {
                return ruleMatchesRegionRow(rule, parentRegion, text(parentRegion.region_type || ''));
            });
        }
        rows.forEach(function (row) {
            if (!row || typeof row !== 'object') {
                return;
            }
            if (parentBlocked) {
                row.embargoed = true;
                return;
            }
            if (countryRules.some(function (rule) {
                return ruleMatchesRegionRow(rule, row, text(row.region_type || ''));
            })) {
                row.embargoed = true;
            }
        });
        return rows;
    }

    function loadChildren(parentRegionId, countryCode, limit) {
        parentRegionId = parentRegionId == null || parentRegionId === '' ? null : Number(parentRegionId);
        countryCode = text(countryCode).toUpperCase();
        limit = limit || 500;
        var cacheKey = 'children:' + text(parentRegionId || 0) + ':' + countryCode + ':' + limit;
        if (!regionSources[cacheKey]) {
            function normalizeChildrenPayload(payload) {
                var data = payload && payload.data !== undefined ? payload.data : payload;
                return Array.isArray(data) ? data : [];
            }
            var rowsPromise;
            if (!window.Weline || !window.Weline.Api) {
                rowsPromise = Promise.resolve([]);
            } else {
                var params = {limit: limit};
                if (parentRegionId && parentRegionId > 0) {
                    params.parent_region_id = parentRegionId;
                }
                if (countryCode) {
                    params.country_code = countryCode;
                }
                rowsPromise = callRegion('children', params).then(normalizeChildrenPayload).catch(function () {
                    return [];
                });
            }
            regionSources[cacheKey] = rowsPromise.then(function (rows) {
                return Promise.all([
                    embargoedCountryCodes().catch(function () { return {}; }),
                    embargoedSubnationalRules(countryCode).catch(function () { return []; })
                ]).then(function (parts) {
                    var blockedMap = parts[0] || {};
                    var rules = parts[1] || [];
                    markChildrenInheritedEmbargo(rows, countryCode, blockedMap);
                    var parentRegion = null;
                    if (parentRegionId && parentRegionId > 0) {
                        parentRegion = {region_id: parentRegionId, region_type: '', country_code: countryCode};
                        rules.forEach(function (rule) {
                            if (Number(rule.region_id || 0) === Number(parentRegionId)) {
                                parentRegion.region_type = text(rule.region_type || '');
                                parentRegion.region_code = text(rule.region_code || '');
                            }
                        });
                    }
                    return markChildrenSubnationalEmbargo(rows, countryCode, rules, parentRegion);
                }).catch(function () {
                    return rows;
                });
            });
        }
        return regionSources[cacheKey];
    }

    function mergeRegions(group, rows) {
        group.regions = group.regions || [];
        var index = {};
        group.regions.forEach(function (region) {
            var key = text(region.region_type) + ':' + text(region.region_id || region.region_code);
            index[key] = true;
        });
        (rows || []).forEach(function (region) {
            var key = text(region.region_type) + ':' + text(region.region_id || region.region_code);
            if (index[key]) {
                return;
            }
            index[key] = true;
            group.regions.push(region);
        });
    }

    /**
     * 有国家控件时禁止用「某国下级」整表覆盖 regions，否则禁运国等会从国家菜单消失。
     * 无国家控件（宿主已锁国）仍可整表替换为该国省市区。
     */
    function adoptRegionRows(group, rows, options) {
        options = options || {};
        rows = Array.isArray(rows) ? rows : [];
        if (group && group.controls && group.controls.country && !options.forceReplace) {
            var hasCountries = (group.regions || []).some(function (region) {
                return text(region.region_type) === 'country';
            });
            var nextHasCountries = rows.some(function (region) {
                return text(region.region_type) === 'country';
            });
            if (!hasCountries && nextHasCountries) {
                group.regions = rows.slice();
                return;
            }
            mergeRegions(group, rows);
            return;
        }
        group.regions = rows;
    }

    function ensureCountryCatalog(group) {
        if (!group || !group.controls || !group.controls.country) {
            return Promise.resolve(group);
        }
        var hasCountries = (group.regions || []).some(function (region) {
            return text(region.region_type) === 'country';
        });
        if (hasCountries) {
            return Promise.resolve(group);
        }
        return loadRegions(group.sourceUrl, '', group.catalog).then(function (all) {
            if (Array.isArray(all) && all.length) {
                group.regions = all;
            }
            return group;
        });
    }

    function ensureLevelChildren(group, level) {
        if (!group) {
            return Promise.resolve([]);
        }
        if (level === 'province') {
            var country = group.state.country;
            var cc = country ? text(country.country_code || country.region_code) : text(group.fixed.country || '');
            if (!cc) {
                return Promise.resolve([]);
            }
            return loadChildren(null, cc, 500).then(function (rows) {
                mergeRegions(group, rows);
                return rows;
            });
        }
        if (level === 'city') {
            var province = group.state.province;
            if (!province || !province.region_id) {
                return Promise.resolve([]);
            }
            return loadChildren(province.region_id, '', 500).then(function (rows) {
                mergeRegions(group, rows);
                return rows;
            });
        }
        if (level === 'district') {
            var city = group.state.city;
            if (!city || !city.region_id) {
                return Promise.resolve([]);
            }
            return loadChildren(city.region_id, '', 500).then(function (rows) {
                mergeRegions(group, rows);
                return rows;
            });
        }
        return Promise.resolve([]);
    }

    function loadCountryProfile(countryCode) {
        countryCode = text(countryCode).toUpperCase();
        function normalizeProfile(payload) {
            var data = payload && payload.data !== undefined ? payload.data : payload;
            if (!data || typeof data !== 'object') {
                return null;
            }
            if (countryCode && data.levels) {
                return data;
            }
            if (countryCode && data.countries && data.countries[countryCode]) {
                return data.countries[countryCode];
            }
            return data.default || data;
        }
        if (!window.Weline || !window.Weline.Api) {
            return Promise.resolve(null);
        }
        var params = {};
        if (countryCode) {
            params.country_code = countryCode;
        }
        return callRegion('country_profile', params).then(normalizeProfile).catch(function () {
            return null;
        });
    }

    function applyCountryProfile(group, profile) {
        if (!group || !profile || !Array.isArray(profile.levels)) {
            return;
        }
        group.autocomplete = profile.autocomplete !== false;
        group.profileLevels = profile.levels.slice();
        syncLevelVisibility(group);
        syncMetadata(group);
    }

    /**
     * 层级可见性：profile 为基线；「能到区就显示区」「能到街道（有街数据）就显示街道」。
     * 已回填的值绝不能因浅 profile 被藏掉或清掉。
     */
    function syncLevelVisibility(group) {
        if (!group || !group.controls) {
            return;
        }
        var profile = Array.isArray(group.profileLevels) ? group.profileLevels : null;
        Object.keys(group.controls).forEach(function (level) {
            var control = group.controls[level];
            if (!control || !control.item) {
                return;
            }
            if (level === 'street') {
                // 有街道目录才显示街道级联；否则隐藏，详细地址用手填 address1
                var showStreet = group.hasStreets === true
                    || !!(group.state.street)
                    || !!(control.field && text(control.field.value));
                control.item.hidden = !showStreet;
                return;
            }
            var inProfile = !profile || profile.indexOf(level) > -1;
            var hasValue = !!(group.state[level])
                || !!(control.field && text(control.field.value));
            var hasOptions = false;
            if (level === 'district') {
                try {
                    hasOptions = optionsFor(group, 'district').length > 0;
                } catch (e) {
                    hasOptions = false;
                }
            }
            // 能到区肯定显示：有值或有下级区选项时强制显示
            var show = inProfile || hasValue || (level === 'district' && hasOptions);
            if (level === 'district' && (hasValue || hasOptions)) {
                show = true;
            }
            control.item.hidden = !show;
            // 禁止因 profile 不允许而清空已回填的区/市等
            if (!show && !hasValue) {
                group.state[level] = null;
                if (control.field) {
                    control.field.value = '';
                }
                if (control.input) {
                    control.input.value = '';
                }
            }
        });
    }

    function loadRegions(sourceUrl, countryCode, catalog) {
        sourceUrl = text(sourceUrl || defaultSourceUrl);
        countryCode = text(countryCode).toUpperCase();
        catalog = text(catalog || 'installed');
        if (catalog !== 'global') {
            catalog = 'installed';
        }
        var cacheKey = sourceUrl + '|' + countryCode + '|' + catalog;
        if (Array.isArray(window.WelineShippingRegions) && !countryCode && catalog === 'installed') {
            return Promise.resolve(normalizeRegions(window.WelineShippingRegions));
        }
        if (!regionSources[cacheKey]) {
            if (!window.Weline || !window.Weline.Api) {
                regionSources[cacheKey] = Promise.resolve(fallbackRegions());
            } else {
                regionSources[cacheKey] = fetchRegionsFromSource(sourceUrl, countryCode, catalog).catch(function () {
                    return fallbackRegions();
                });
            }
        }

        return regionSources[cacheKey];
    }

    function groupFor(code, sourceUrl, catalog) {
        if (!groups[code]) {
                groups[code] = {
                code: code,
                controls: {},
                state: {},
                regions: fallbackRegions(),
                streetOptions: [],
                hasStreets: false,
                streetParentId: 0,
                fixed: {},
                cascade: true,
                catalog: text(catalog || 'installed') === 'global' ? 'global' : 'installed',
                sourceUrl: frontendRoute(sourceUrl || defaultSourceUrl)
            };
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
        var field = null;
        // Prefer the address root / nearest section so dual instances (shipping + billing) do not steal fields.
        if (root && root.querySelector) {
            field = root.querySelector('[name="' + name + '"]');
        }
        if (!field && root && root.closest) {
            var scope = root.closest(
                '[data-w-address-shell], [data-billing-address-cascade], [data-shipping-address-cascade],'
                + ' [data-billing-section], [data-shipping-section], [data-billing-editor], [data-address-editor]'
            );
            if (scope) {
                field = scope.querySelector('[name="' + name + '"]');
            }
        }
        if (!field && form) {
            field = form.querySelector('[name="' + name + '"]');
        }
        if (!field) {
            field = createHidden(name, root);
        }
        if (field.tagName && field.tagName.toLowerCase() === 'input') {
            field.type = 'hidden';
        }
        var holder = field.closest && field.closest('.account-address-form__field');
        // Do not hide the account field that wraps postal/detail shell + cascade.
        if (holder && !holder.querySelector('[data-w-address-shell], [data-w-address]')) {
            holder.hidden = true;
        }
        return field;
    }

    function metaFieldName(group, name) {
        return text((group && group.metaPrefix) || '') + name;
    }

    function renderRoot(root, config, levels) {
        var labels = labelsFor(config);
        root.classList.toggle('w-address--single', levels.length === 1);
        root.innerHTML = levels.map(function (level) {
            var placeholder = level === 'country' ? labels.selectCountry : (level === 'province' ? labels.selectCountryFirst : (level === 'city' ? labels.selectProvinceFirst : labels.selectCityFirst));
            return '<div class="w-field w-address__item" data-address-level="' + level + '"><label class="w-field__label">' + escapeHtml(labels[level] || level) + '</label><div class="w-field__control w-address__control"><input class="w-input w-address__input" type="text" autocomplete="off" placeholder="' + escapeHtml(placeholder) + '"><span class="w-address__arrow">\u25be</span></div><div class="w-address__menu" data-w-float-surface hidden></div></div>';
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
            var postalFilter = Array.isArray(group.postalCountryFilter) ? group.postalCountryFilter : [];
            var countries = regions.filter(function (region) {
                return text(region.region_type) === 'country';
            });
            if (postalFilter.length) {
                // 邮编未清空：候选国置顶，其后接其它国家（忽略 fixed.country）
                var pinned = [];
                var rest = [];
                var seen = {};
                postalFilter.forEach(function (code) {
                    code = text(code).toUpperCase();
                    countries.forEach(function (region) {
                        var cc = text(region.country_code || region.region_code).toUpperCase();
                        if (cc === code && !seen[cc]) {
                            seen[cc] = true;
                            pinned.push(region);
                        }
                    });
                });
                countries.forEach(function (region) {
                    var cc = text(region.country_code || region.region_code).toUpperCase();
                    if (seen[cc]) {
                        return;
                    }
                    if (!filter.length || filter.some(function (item) {
                        return matchesValue(region, item);
                    })) {
                        seen[cc] = true;
                        rest.push(region);
                    }
                });
                rest.sort(function (a, b) {
                    return regionHotSortOrder(a) - regionHotSortOrder(b);
                });
                return pinned.concat(rest);
            }
            return countries.filter(function (region) {
                return !filter.length || filter.some(function (item) {
                    return matchesValue(region, item);
                });
            }).sort(function (a, b) {
                return regionHotSortOrder(a) - regionHotSortOrder(b);
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

        if (level === 'street') {
            var streets = Array.isArray(group.streetOptions) ? group.streetOptions : [];
            if (!streets.length) {
                return [];
            }
            return streets.filter(function (region) {
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
        } else if (level === 'street') {
            regionId = metadataValue(group, 'street_id');
            regionCode = metadataValue(group, 'street_code');
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
            // 邮编多国待选时不要用 fixed.country 把「中国」写回
            if (!(Array.isArray(group.postalCountryFilter) && group.postalCountryFilter.length)) {
                group.state.country = findRegion(group, 'country', splitFilter(group.fixed.country)[0]);
            }
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
            // 邮编多国待选时不要自动落国，等用户点选
            // 全球国家单选（如供应商申请）也不要默认 CN，避免看起来像站点支持地区列表。
            if (!(Array.isArray(group.postalCountryFilter) && group.postalCountryFilter.length)
                && !(group.catalog === 'global' && group.controls.country)) {
                group.state.country = findRegion(group, 'country', 'CN') || firstAllowed(group, 'country');
            }
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
        if (group.controls.street && group.controls.street.field.value) {
            group.state.street = findRegion(group, 'street', group.controls.street.field.value);
        }
        if (!group.state.street) {
            group.state.street = findRegionByMetadata(group, 'street');
        }
        if (group.state.street && group.controls.street) {
            group.controls.street.field.value = labelOf(group.state.street);
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
            return findOrCreateField(root, form, metaFieldName(group, name));
        }
        var country = group.state.country;
        var province = group.state.province;
        var city = group.state.city;
        var district = group.state.district;
        var street = group.state.street;
        metadataField('country_code').value = country ? text(country.country_code || country.region_code) : '';
        metadataField('province_code').value = province ? text(province.region_code) : '';
        metadataField('province_region_id').value = province ? text(province.region_id) : '';
        metadataField('city_code').value = city ? text(city.region_code) : '';
        metadataField('city_region_id').value = city ? text(city.region_id) : '';
        metadataField('district_code').value = district ? text(district.region_code) : '';
        metadataField('district_region_id').value = district ? text(district.region_id) : '';
        metadataField('street_id').value = street ? text(street.street_id || street.region_id) : '';
        metadataField('street_code').value = street ? text(street.region_code) : '';
        if (group.controls.street && group.controls.street.field && !street && canUseManualInput(group, group.controls.street)) {
            // keep free-text street already written to the named field
        } else if (group.controls.street && group.controls.street.field && street) {
            group.controls.street.field.value = labelOf(street);
        }
        syncPostalField(group, form);
    }

    function resolvePostalCode(group) {
        var street = group.state.street;
        var district = group.state.district;
        var city = group.state.city;
        var province = group.state.province;
        var region = street || district || city || province || null;
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
        var root = null;
        Object.keys(group.controls || {}).some(function (level) {
            root = group.controls[level].root;
            return !!root;
        });
        var postalField = findPostalFieldForRoot(root);
        if (!postalField && form) {
            var postalName = text(group.postalName || 'postal_code') || 'postal_code';
            postalField = form.querySelector('[name="' + postalName + '"]');
        }
        if (!postalField) {
            return;
        }
        var next = resolvePostalCode(group);
        var current = text(postalField.value).trim();
        var pending = text(group.pendingPostalCode || '').trim();
        // Postal-lookup: keep the typed value (SW1A 1AA); catalog may only store outward SW1A.
        if (pending) {
            postalField.value = pending;
            return;
        }
        if (next && !current) {
            postalField.value = next;
        }
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
        if (level === 'street') {
            return !group.state.district && !group.state.city;
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
        if (level === 'district') {
            return group.state.city || group.fixed.city || group.state.province || group.fixed.province || group.state.country || group.fixed.country ? labels.selectDistrict : labels.selectCityFirst;
        }
        if (level === 'street') {
            if (group.hasStreets) {
                return labels.selectStreet || labels.enterStreet;
            }
            return labels.enterStreet || labels.selectStreet;
        }
        return labels.selectDistrict;
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

        if (level === 'country') {
            hits.sort(function (a, b) {
                var sa = regionHotSortOrder(a);
                var sb = regionHotSortOrder(b);
                if (sa !== sb) {
                    return sa - sb;
                }
                return text(labelOf(a.region)).localeCompare(text(labelOf(b.region)), 'zh');
            });
        }

        return hits.slice(0, level === 'country' && !needle ? 400 : 50);
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
        if (hit && hit.region && hit.region.embargoed) {
            return;
        }
        if (hit && hit.formatted_address) {
            var fa = hit.formatted_address || {};
            evaluateEmbargo({
                country_code: text(fa.country_code || ''),
                province_code: text(fa.province_code || ''),
                province_region_id: Number(fa.province_region_id || 0),
                city_code: text(fa.city_code || ''),
                city_region_id: Number(fa.city_region_id || 0),
                district_code: text(fa.district_code || ''),
                district_region_id: Number(fa.district_region_id || 0)
            }).then(function (result) {
                if (result && result.blocked) {
                    group.embargo = result;
                    updateGroup(group);
                    return;
                }
                applyFormattedAddress(group, hit.formatted_address);
                closeMenus(group);
                var emitControl = group.controls[hit.level] || control;
                if (emitControl && emitControl.field) {
                    emitControl.field.dispatchEvent(new Event('change', {bubbles: true}));
                }
            });
            return;
        }
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
        refreshEmbargoState(group);
        closeMenus(group);
        var emitControlLocal = group.controls[hit.level] || control;
        emitControlLocal.field.dispatchEvent(new Event('change', {bubbles: true}));
        var selectedCountry = group.state.country;
        if (selectedCountry || group.fixed.country) {
            loadCountryProfile(text((selectedCountry && (selectedCountry.country_code || selectedCountry.region_code)) || group.fixed.country)).then(function (profile) {
                applyCountryProfile(group, profile);
                updateGroup(group);
            });
        }
        if (text(control.level) === 'country' && text(group.pendingPostalCode)) {
            var pendingPostal = text(group.pendingPostalCode);
            var pickedCode = selectedCountry
                ? text(selectedCountry.country_code || selectedCountry.region_code).toUpperCase()
                : '';
            // 邮编未清空则保留 postalCountryFilter 置顶；仅回填下级区划
            if (pendingPostal && pickedCode) {
                postalLookup(pickedCode, pendingPostal, 8).then(function (rows) {
                    if (!Array.isArray(rows) || !rows.length) {
                        return;
                    }
                    window.WelineThemeAddress.applyPostalCandidate(group.code, Object.assign({
                        country_code: pickedCode
                    }, rows[0] || {}));
                });
            }
        }
    }

    function applyFormattedAddress(group, address) {
        address = address || {};
        var countryCode = text(address.country_code || '').toUpperCase();
        if (countryCode) {
            group.fixed.country = group.fixed.country || countryCode;
            ensureCountryInRegions(group, countryCode, countryCode);
            if (group.controls.country) {
                var countryRegion = findRegion(group, 'country', countryCode) || {
                    region_id: 0,
                    parent_region_id: 0,
                    country_code: countryCode,
                    region_code: countryCode,
                    region_name: countryCode,
                    region_type: 'country'
                };
                group.state.country = countryRegion;
                group.controls.country.field.value = labelOf(countryRegion);
            }
            loadCountryProfile(countryCode).then(function (profile) {
                applyCountryProfile(group, profile);
                updateGroup(group);
            });
        }
        ['province', 'city', 'district', 'street'].forEach(function (level) {
            var name = text(address[level] || '').trim();
            var code = text(address[level + '_code'] || '').trim();
            var regionId = text(address[level + '_region_id'] || address[level === 'street' ? 'street_id' : (level + '_region_id')] || '').trim();
            if (!group.controls[level]) {
                return;
            }
            if (!name && !code && !regionId) {
                group.state[level] = null;
                group.controls[level].field.value = '';
                group.controls[level].input.value = '';
                return;
            }
            var region = null;
            if (regionId) {
                region = findRegionById(group, regionId) || (level === 'street' ? (group.streetOptions || []).find(function (item) {
                    return text(item.street_id || item.region_id) === regionId;
                }) : null);
            }
            if (!region && code) {
                region = findRegion(group, level, code);
            }
            if (!region && name) {
                region = findRegion(group, level, name);
            }
            if (!region) {
                region = {
                    region_id: regionId || 0,
                    street_id: level === 'street' ? (regionId || 0) : undefined,
                    parent_region_id: 0,
                    country_code: countryCode,
                    region_code: code || name,
                    region_name: name || code,
                    region_type: level,
                    postal_code: text(address.postal_code || '')
                };
                if (level === 'street') {
                    group.streetOptions = (group.streetOptions || []).concat([region]);
                } else {
                    group.regions.push(region);
                }
            }
            group.state[level] = region;
            group.controls[level].field.value = labelOf(region);
            group.controls[level].input.value = labelOf(region);
        });
        if (text(address.postal_code || '')) {
            var form = null;
            Object.keys(group.controls).some(function (level) {
                form = group.controls[level].root && group.controls[level].root.closest('form');
                return !!form;
            });
            if (form) {
                var postalField = form.querySelector('[name="postal_code"]');
                if (postalField) {
                    postalField.value = text(address.postal_code);
                }
            }
        }
        syncMetadata(group);
        updateGroup(group);
    }

    function renderMenu(group, control, keyword) {
        var labels = group.labels || defaultLabels;
        var needle = text(keyword).trim().toLowerCase();
        var localHits = searchHits(group, control, keyword).map(function (hit) {
            return {
                level: hit.level,
                region: hit.region,
                label: pathLabelForHit(group, hit, control.level),
                formatted_address: null
            };
        });

        function paint(hits) {
            hits = prioritizePostalCountryHits(group, control, hits || []);
            if (!hits.length) {
                control.menu.innerHTML = '<div class="w-address__empty">' + escapeHtml(canUseManualInput(group, control) && needle ? labels.manual : labels.empty) + '</div>';
                placeSingleMenu(control);
                return;
            }
            // 邮编多国待选：若列表里至少有一个本站支持的国家，则禁用不支持项；
            // 若全部不支持（如 1010→NZ/AU），仍允许选择，避免无法回填。
            var lockUnsupported = false;
            var postalFilter = Array.isArray(group.postalCountryFilter) ? group.postalCountryFilter : [];
            var postalSet = {};
            postalFilter.forEach(function (code) {
                postalSet[text(code).toUpperCase()] = true;
            });
            if (postalFilter.length && control.level === 'country') {
                lockUnsupported = hits.some(function (hit) {
                    return hit.region && !hit.region.postal_unsupported;
                });
            }
            var html = [];

            function pushCountryOption(hit, index, isPostal) {
                var unsupported = !!(hit.region && (hit.region.postal_unsupported || hit.region.embargoed));
                var disabled = unsupported && (lockUnsupported || !!(hit.region && hit.region.embargoed));
                var label = hit.label;
                if (hit.region && hit.region.embargoed) {
                    label = label + '（' + text(labels.embargoedRegion || labels.unsupportedCountry || 'unsupported') + '）';
                } else if (unsupported) {
                    label = label + '（' + text(labels.unsupportedCountry || 'unsupported') + '）';
                }
                html.push('<button type="button" class="w-address__option' + (disabled ? ' is-disabled' : '') + (hit.region && hit.region.embargoed ? ' is-embargoed' : '') + (isPostal ? ' is-postal-match' : '') + '"'
                    + ' data-index="' + index + '"'
                    + (disabled ? ' disabled aria-disabled="true"' : '')
                    + '>' + escapeHtml(label) + '</button>');
            }

            if (control.level === 'country') {
                var sections = [];
                if (postalFilter.length) {
                    var postalEntries = [];
                    var restEntries = [];
                    hits.forEach(function (hit, index) {
                        var cc = countryCodeFromMenuHit(hit);
                        if (postalSet[cc]) {
                            postalEntries.push({hit: hit, index: index});
                        } else {
                            restEntries.push({hit: hit, index: index});
                        }
                    });
                    if (postalEntries.length) {
                        sections.push({
                            label: text(labels.postalCountryGroup || 'postal'),
                            entries: postalEntries,
                            postal: true
                        });
                    }
                    groupCountryEntriesByContinent(restEntries, labels, {includePopular: true}).forEach(function (section) {
                        sections.push(section);
                    });
                } else {
                    sections = groupCountryEntriesByContinent(
                        hits.map(function (hit, index) {
                            return {hit: hit, index: index};
                        }),
                        labels,
                        {includePopular: true}
                    );
                }
                sections = assignGroupAnchors(sections);
                html.push(buildGroupJumpHtml(sections));
                sections.forEach(function (section) {
                    if (section.label) {
                        html.push('<div class="w-address__group-label" data-group-anchor="' + escapeHtml(String(section.anchorId)) + '">' + escapeHtml(section.label) + '</div>');
                    }
                    (section.entries || []).forEach(function (entry) {
                        pushCountryOption(entry.hit, entry.index, !!section.postal);
                    });
                });
            } else {
                hits.forEach(function (hit, index) {
                    pushCountryOption(hit, index, false);
                });
            }
            control.menu.innerHTML = html.join('');
            bindGroupJumpNavigation(control.menu);
            control.menu.querySelectorAll('.w-address__option').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (button.disabled || button.classList.contains('is-disabled')) {
                        return;
                    }
                    var hit = hits[Number(button.dataset.index)];
                    if (!hit) {
                        return;
                    }
                    // 禁运命中禁止确认；邮编歧义下「本站未支持」可按 lockUnsupported 放行
                    if (hit.region && hit.region.embargoed) {
                        return;
                    }
                    if (hit.region && hit.region.postal_unsupported && lockUnsupported) {
                        return;
                    }
                    applySearchHit(group, control, hit);
                });
            });
            placeSingleMenu(control);
        }

        function markHitsForMenu(hits, blockedMap, rules) {
            (hits || []).forEach(function (hit) {
                if (!hit.region && hit.formatted_address) {
                    var fa = hit.formatted_address || {};
                    var lvl = text(hit.level || control.level || 'province');
                    hit.region = {
                        region_id: Number(fa[lvl + '_region_id'] || fa.province_region_id || fa.city_region_id || fa.district_region_id || 0),
                        region_code: text(fa[lvl + '_code'] || fa.province_code || fa.city_code || fa.district_code || ''),
                        region_name: text(fa[lvl] || fa.province || fa.city || fa.district || hit.label || ''),
                        region_type: lvl,
                        country_code: text(fa.country_code || selectedCountry).toUpperCase()
                    };
                }
                if (!hit.region) {
                    return;
                }
                if (control.level === 'country') {
                    var cc = text(hit.region.country_code || hit.region.region_code).toUpperCase();
                    if (blockedMap[cc]) {
                        hit.region.embargoed = true;
                    }
                    return;
                }
                var hitCc = text(hit.region.country_code || selectedCountry).toUpperCase();
                if (hitCc && blockedMap[hitCc]) {
                    hit.region.embargoed = true;
                    return;
                }
                var ancestorBlocked = false;
                ['country', 'province', 'city', 'district'].forEach(function (lvl) {
                    var node = group.state[lvl];
                    if (!node || ancestorBlocked) {
                        return;
                    }
                    if (node.embargoed) {
                        ancestorBlocked = true;
                        return;
                    }
                    if (rules.some(function (rule) {
                        return ruleMatchesRegionRow(rule, node, lvl);
                    })) {
                        ancestorBlocked = true;
                    }
                });
                if (ancestorBlocked) {
                    hit.region.embargoed = true;
                    return;
                }
                if (rules.some(function (rule) {
                    if (ruleMatchesRegionRow(rule, hit.region, control.level)
                        || ruleMatchesRegionRow(rule, hit.region, text(hit.region.region_type || ''))) {
                        return true;
                    }
                    // 同国同级：建议条目可能缺 id，用 code/名兜底
                    if (text(rule.country_code).toUpperCase() !== hitCc) {
                        return false;
                    }
                    if (text(rule.region_type) !== text(control.level) && text(rule.region_type) !== text(hit.region.region_type || '')) {
                        return false;
                    }
                    var ruleCode = text(rule.region_code).toUpperCase();
                    var rowCode = text(hit.region.region_code).toUpperCase();
                    if (ruleCode && rowCode && ruleCode === rowCode) {
                        return true;
                    }
                    var ruleId = Number(rule.region_id || 0);
                    var rowId = Number(hit.region.region_id || 0);
                    if (ruleId > 0 && rowId > 0 && ruleId === rowId) {
                        return true;
                    }
                    var label = text(hit.label || hit.region.region_name || '');
                    return !!(ruleId > 0 && label && group.regions && (group.regions || []).some(function (r) {
                        return Number(r.region_id || 0) === ruleId && label.indexOf(text(r.region_name || '')) >= 0;
                    }));
                })) {
                    hit.region.embargoed = true;
                }
            });
            // 同源重复项：任一禁运则同 id/同名一并禁用
            var blockedIds = {};
            var blockedNames = {};
            (hits || []).forEach(function (hit) {
                if (!(hit && hit.region && hit.region.embargoed)) {
                    return;
                }
                var id = Number(hit.region.region_id || 0);
                if (id > 0) {
                    blockedIds[id] = true;
                }
                var nm = text(hit.region.region_name || hit.label || '');
                if (nm) {
                    blockedNames[nm] = true;
                    blockedNames[nm.replace(/（.*?）/g, '')] = true;
                }
            });
            (hits || []).forEach(function (hit) {
                if (!(hit && hit.region) || hit.region.embargoed) {
                    return;
                }
                var id = Number(hit.region.region_id || 0);
                var nm = text(hit.region.region_name || hit.label || '').replace(/（.*?）/g, '');
                if ((id > 0 && blockedIds[id]) || (nm && blockedNames[nm])) {
                    hit.region.embargoed = true;
                }
            });
            (hits || []).forEach(function (hit) {
                if (!hit || hit.region) {
                    return;
                }
                var label = text(hit.label || '').replace(/（.*?）/g, '').trim();
                var blocked = !!(label && (blockedNames[label] || Object.keys(blockedNames).some(function (n) {
                    return n && label.indexOf(n) >= 0;
                })));
                if (blocked) {
                    hit.region = {
                        region_id: 0,
                        region_code: '',
                        region_name: label,
                        region_type: text(control.level || 'province'),
                        country_code: selectedCountry,
                        embargoed: true
                    };
                }
            });
            return hits;
        }

        function paintMarked(hits) {
            return Promise.all([
                embargoedCountryCodes().catch(function () { return {}; }),
                control.level === 'country'
                    ? Promise.resolve([])
                    : embargoedSubnationalRules(selectedCountry).catch(function () { return []; })
            ]).then(function (parts) {
                markHitsForMenu(hits, parts[0] || {}, parts[1] || []);
                paint(hits);
                return hits;
            }).catch(function () {
                paint(hits);
                return hits;
            });
        }

        // 标记当前列表中的禁运项（国家级或省市区子级）后再渲染，避免首屏可点竞态
        evaluateEmbargo(buildAddressPayloadFromGroup(group)).then(function (current) {
            group.embargo = current || {blocked: false};
        });
        var selectedCountry = '';
        if (group.state.country) {
            selectedCountry = text(group.state.country.country_code || group.state.country.region_code).toUpperCase();
        } else if (group.fixed && group.fixed.country) {
            selectedCountry = text(group.fixed.country).toUpperCase();
        }
        if (!selectedCountry) {
            var metaCc = group.root && group.root.querySelector('[name="country_code"], [data-address-meta="country_code"]');
            selectedCountry = text(metaCc && metaCc.value).toUpperCase();
        }
        paintMarked(localHits);

        if (!needle || group.autocomplete === false || text(keyword).trim().length < 1) {
            return;
        }

        var requestToken = String(Date.now()) + '-' + Math.random();
        control.suggestToken = requestToken;
        var countryCode = selectedCountry || '';
        if (!countryCode && group.state.country) {
            countryCode = text(group.state.country.country_code || group.state.country.region_code);
        } else if (!countryCode && group.fixed.country) {
            countryCode = text(group.fixed.country);
        }
        suggestRegions(keyword, countryCode, 8).then(function (rows) {
            if (control.suggestToken !== requestToken) {
                return;
            }
            var remoteHits = (rows || []).map(function (row) {
                return {
                    level: text(row.matched_level || 'district'),
                    region: null,
                    label: text(row.label || ''),
                    formatted_address: row.formatted_address || null
                };
            }).filter(function (hit) {
                return hit.label && hit.formatted_address;
            });
            var seen = {};
            var merged = [];
            remoteHits.concat(localHits).forEach(function (hit) {
                var key = hit.label;
                if (seen[key]) {
                    return;
                }
                seen[key] = true;
                merged.push(hit);
            });
            // 远程建议可能带禁运省市区：合并后必须再打标再绘
            paintMarked(prioritizePostalCountryHits(group, control, merged).slice(0, 20));
        });
    }

    function canUseManualInput(group, control) {
        if (control.level === 'country' || !control.searchable || isDisabled(group, control.level)) {
            return false;
        }
        if (control.level === 'street') {
            return !group.hasStreets || optionsFor(group, 'street').length === 0;
        }
        return optionsFor(group, control.level).length === 0;
    }

    function syncManualInput(group, control) {
        if (!canUseManualInput(group, control)) {
            return;
        }
        control.field.value = text(control.input.value).trim();
        group.state[control.level] = null;
        clearAfter(group, control.level);
    }

    function uiFloating() {
        return window.Weline && window.Weline.UI && window.Weline.UI.floating
            ? window.Weline.UI.floating
            : null;
    }

    function syncSingleMenuWidth(control) {
        if (!control || !control.menu || !control.item) {
            return;
        }
        var box = control.item.querySelector('.w-address__control') || control.item;
        var width = Math.round(box.getBoundingClientRect().width);
        if (width > 0) {
            control.menu.style.setProperty('--w-floating-inline-size', width + 'px');
            control.menu.style.minWidth = width + 'px';
        }
    }

    function ensureSingleFloat(control) {
        if (!control || !control.menu || !control.item) {
            return null;
        }
        if (control.floatApi) {
            return control.floatApi;
        }
        var floating = uiFloating();
        if (!floating || typeof floating.attach !== 'function') {
            return null;
        }
        control.menu.setAttribute('data-w-float-surface', '');
        control.item.setAttribute('data-w-placement', 'bottom-start');
        control.floatApi = floating.attach(control.item, {placement: 'bottom-start'});
        return control.floatApi;
    }

    function placeSingleMenu(control) {
        if (!control || !control.item || !control.menu) {
            return;
        }
        if (!control.item.classList.contains('is-open')) {
            return;
        }
        control.menu.hidden = false;
        syncSingleMenuWidth(control);
        var api = ensureSingleFloat(control);
        if (api) {
            if (typeof api.show === 'function') {
                api.show();
            } else if (typeof api.sync === 'function') {
                api.sync();
            } else if (typeof api.place === 'function') {
                api.place();
            }
            return;
        }
        // Fallback when Weline.UI unavailable: keep absolute under the field.
        control.menu.style.position = 'absolute';
        control.menu.style.top = 'calc(100% + 6px)';
        control.menu.style.insetInline = '0';
        control.menu.style.zIndex = '1080';
        control.menu.style.display = 'block';
    }

    function hideSingleFloat(control) {
        if (!control) {
            return;
        }
        if (control.floatApi && typeof control.floatApi.hide === 'function') {
            control.floatApi.hide();
        }
        if (control.menu) {
            control.menu.hidden = true;
            if (control.menu.style) {
                control.menu.style.position = '';
                control.menu.style.top = '';
                control.menu.style.insetInline = '';
                control.menu.style.zIndex = '';
                control.menu.style.display = '';
                control.menu.style.minWidth = '';
                control.menu.style.removeProperty('--w-floating-inline-size');
            }
        }
    }

    function closeMenus(group) {
        Object.keys(group.controls).forEach(function (level) {
            var control = group.controls[level];
            control.item.classList.remove('is-open');
            hideSingleFloat(control);
        });
    }

    function updateControl(group, control) {
        refreshState(group);
        var current = group.state[control.level];
        if (current) {
            control.field.value = labelOf(current);
        }
        control.input.value = current ? labelOf(current) : control.field.value;
        var labels = group.labels || defaultLabels;
        var loading = !!group.cascadeLoading;
        control.input.placeholder = loading
            ? text(labels.loading || defaultLabels.loading)
            : placeholderFor(group, control.level);
        var disabled = isDisabled(group, control.level) || loading;
        control.input.disabled = disabled;
        var controlEl = control.item.querySelector('.w-address__control');
        controlEl.classList.toggle('is-disabled', disabled && !loading);
        controlEl.classList.toggle('is-loading', loading);
        var embargoed = embargoCoversControl(group.embargo, control.level);
        controlEl.classList.toggle('is-embargoed', embargoed);
        if (embargoed) {
            control.input.setAttribute('aria-invalid', 'true');
            control.item.setAttribute('title', text(group.embargo.message || (group.labels || defaultLabels).embargoedRegion));
        } else {
            control.input.removeAttribute('aria-invalid');
            control.item.removeAttribute('title');
        }
        control.item.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    function setCascadeLoading(codeOrRoot, busy) {
        var group = resolveAddressGroup(codeOrRoot);
        if (!group) {
            return false;
        }
        busy = !!busy;
        if (!!group.cascadeLoading === busy) {
            // still refresh DOM in case controls remounted
        }
        group.cascadeLoading = busy;
        if (busy) {
            closeMenus(group);
        }
        Object.keys(group.controls || {}).forEach(function (level) {
            updateControl(group, group.controls[level]);
        });
        return true;
    }

    function updateGroup(group) {
        Object.keys(group.controls).forEach(function (level) {
            updateControl(group, group.controls[level]);
        });
        syncLevelVisibility(group);
        syncMetadata(group);
        if (group.controls.street) {
            var token = String(Date.now()) + '-' + Math.random();
            group.streetProbeToken = token;
            probeAndLoadStreets(group).then(function () {
                if (group.streetProbeToken !== token) {
                    return;
                }
                syncLevelVisibility(group);
                if (group.controls.street && !group.controls.street.item.hidden) {
                    updateControl(group, group.controls.street);
                }
                syncMetadata(group);
            });
        }
        // 选到市后探测是否有区：有区则显示区
        if (group.controls.district && group.state.city && !group.state.district) {
            var cityId = Number(group.state.city.region_id || 0);
            if (cityId > 0) {
                var districtToken = String(Date.now()) + '-d';
                group.districtProbeToken = districtToken;
                loadChildren(cityId, '', 500).then(function (rows) {
                    if (group.districtProbeToken !== districtToken) {
                        return;
                    }
                    mergeRegions(group, rows || []);
                    syncLevelVisibility(group);
                });
            }
        }
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
            control.menu.hidden = false;
            ensureLevelChildren(group, control.level).then(function () {
                renderMenu(group, control, control.searchable ? text(control.input.value) : '');
                placeSingleMenu(control);
            });
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
            control.menu.hidden = false;
            ensureLevelChildren(group, control.level).then(function () {
                renderMenu(group, control, control.input.value);
                placeSingleMenu(control);
            });
            syncManualInput(group, control);
        });
        control.input.addEventListener('blur', function () {
            syncManualInput(group, control);
        });
    }

    function selectionKey(row) {
        return [
            text(row.region_type || ''),
            text(row.country_code || '').toUpperCase(),
            String(row.region_id || 0),
            text(row.region_code || '').toUpperCase(),
            String(row.street_id || 0)
        ].join('|');
    }

    function mountMulti(root, config) {
        if (!root) {
            return;
        }
        root.dataset.wAddressReady = 'true';
        var code = text(config.code || '');
        if (!code) {
            code = 'w-address-multi-' + (++autoCode);
        }
        root.dataset.addressCode = code;
        root.classList.add('w-address--multi');

        var multiLevels = Array.isArray(config.multiLevels) && config.multiLevels.length
            ? config.multiLevels
            : text(config.for || 'country|province|district').split('|');
        multiLevels = order.filter(function (level) {
            return multiLevels.indexOf(level) > -1;
        });
        if (!multiLevels.length) {
            multiLevels = ['country', 'province', 'district'];
        }
        var labels = labelsFor(config);
        var catalog = text(config.catalog || 'global') === 'installed' ? 'installed' : 'global';
        var sourceUrl = frontendRoute(defaultSourceUrl);
        var configuredUrl = text(config.sourceUrl || '');
        if (configuredUrl && configuredUrl.indexOf('/shipping/frontend/region/') > -1) {
            sourceUrl = frontendRoute(defaultSourceUrl);
        } else if (configuredUrl) {
            sourceUrl = frontendRoute(configuredUrl);
        }

        var typeLabels = {
            country: labels.country || 'country',
            province: labels.province || 'province',
            city: labels.city || 'city',
            district: labels.district || 'district',
            street: labels.street || 'street'
        };
        var searchPlaceholder = {
            country: labels.searchCountry || ('搜索并添加' + (labels.country || '')),
            province: labels.searchProvince || ('搜索并添加' + (labels.province || '')),
            city: labels.searchCity || ('搜索并添加' + (labels.city || '')),
            district: labels.searchDistrict || ('搜索并添加' + (labels.district || '')),
            street: labels.searchStreet || ('搜索并添加' + (labels.street || ''))
        };

        var selected = [];
        try {
            var seed = root.getAttribute('data-multi-seed') || '[]';
            var parsed = JSON.parse(seed);
            if (Array.isArray(parsed)) {
                selected = parsed.map(function (row) {
                    var next = Object.assign({}, row || {});
                    if (!text(next.label) && !text(next.region_name)) {
                        next.label = text(next.region_code || next.country_code || '');
                    }
                    return next;
                });
            }
        } catch (e) {
            selected = [];
        }

        var optionPools = {};
        var poolHints = {};
        multiLevels.forEach(function (level) {
            optionPools[level] = [];
            poolHints[level] = '';
        });

        root.innerHTML = ''
            + '<style data-w-address-multi-style>'
            + '.w-address__multi{display:flex;flex-direction:column;gap:var(--weline-layout-spacing-md,12px);color:var(--weline-theme-text,inherit);}'
            + '.w-address__multi-chips{display:flex;flex-wrap:wrap;gap:var(--weline-layout-spacing-xs,6px);align-items:center;min-height:1.5rem;}'
            + '.w-address__multi .w-address--multi{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--weline-layout-spacing-md,12px);margin:0;}'
            + '.w-address__multi .w-address__item{position:relative;min-width:0;--weline-theme-field-label-bg:var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff));}'
            + '.w-address__multi .w-address__item.w-field{gap:0;}'
            + '.w-address__multi .w-field__label{font-weight:var(--weline-layout-font-weight-semibold,600);}'
            + '.w-address__multi .w-address__control{position:relative;display:flex;align-items:center;min-height:var(--weline-theme-control-height,40px);border:var(--weline-theme-border-width,1px) var(--weline-theme-border-style,solid) var(--weline-theme-border-color,#d0d5dd);border-radius:var(--weline-radius-md,var(--weline-theme-radius-md,8px));background:var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff));box-shadow:var(--theme-input-shadow,none);color:var(--weline-theme-text,inherit);transition:border-color .16s ease,box-shadow .16s ease,background .16s ease;}'
            + '.w-address__multi .w-address__control:focus-within{border-color:var(--weline-theme-primary,var(--weline-theme-focus-outline,#2563eb));box-shadow:var(--weline-theme-focus-ring,0 0 0 3px var(--weline-theme-focus-ring-color,rgba(37,99,235,.15)));}'
            + '.w-address__multi .w-address__control .w-input,.w-address__multi .w-address__input{width:100%;min-width:0;height:var(--weline-component-control-height,var(--weline-theme-control-height,40px));min-height:0;padding:0 28px 0 12px;border:0;outline:0;box-shadow:none;background:transparent;color:var(--weline-theme-text,inherit);caret-color:var(--weline-theme-text,currentColor);font:inherit;}'
            + '.w-address__multi .w-address__control .w-input::placeholder,.w-address__multi .w-address__input::placeholder{color:var(--weline-theme-text-muted,#98a2b3);}'
            + '.w-address__multi .w-address__arrow{position:absolute;inset-inline-end:12px;color:var(--weline-theme-text-muted,#98a2b3);font-size:12px;pointer-events:none;}'
            // Menu visuals + floating coords. Do NOT set top/inset-inline here — theme absolute+inset fights portal.
            // Clear layout so Weline.UI.floating --w-floating-* (flip / visualViewport bounds) owns placement.
            + '.w-address__menu[data-multi-menu],.w-address__menu[data-w-float-surface]{box-sizing:border-box;position:fixed;inset:auto;top:auto;right:auto;bottom:auto;left:auto;inset-inline:auto;margin:0;z-index:var(--weline-z-menu,1080);max-block-size:min(260px,var(--w-floating-max-block-size,70vh));overflow:auto;padding:6px;border:var(--weline-theme-border-width,1px) var(--weline-theme-border-style,solid) var(--weline-theme-border-color,#d0d5dd);border-radius:var(--weline-radius-md,var(--weline-theme-radius-md,8px));background:var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff));color:var(--weline-theme-text,inherit);box-shadow:var(--weline-theme-shadow-lg,0 12px 32px rgba(16,24,40,.14));}'
            + '.w-address__menu[data-multi-menu][hidden],.w-address__menu[data-w-float-surface][hidden]{display:none!important;}'
            + '.w-address__menu[data-multi-menu]:not([hidden]),.w-address__menu[data-w-float-surface]:not([hidden]){display:block;}'
            + '.w-address__menu[data-w-floating-positioned]{top:max(var(--w-floating-top,0px),var(--w-floating-viewport-top,.5rem));left:max(var(--w-floating-left,0px),var(--w-floating-viewport-left,.5rem));inline-size:var(--w-floating-inline-size,auto);max-inline-size:min(var(--w-floating-max-inline-size,calc(100dvw - 1rem)),calc(var(--w-floating-viewport-right,calc(100dvw - .5rem)) - max(var(--w-floating-left,0px),var(--w-floating-viewport-left,.5rem))));max-block-size:min(260px,var(--w-floating-max-block-size,70vh),calc(var(--w-floating-viewport-bottom,calc(100dvh - .5rem)) - max(var(--w-floating-top,0px),var(--w-floating-viewport-top,.5rem))));transform-origin:var(--w-floating-transform-origin,top);}'
            + '.w-address__menu[data-w-floating-positioned="pending"]{visibility:hidden;}'
            + '.w-address__menu[data-multi-menu] .w-address__option{display:block;width:100%;padding:8px 10px;border:0;border-radius:8px;background:transparent;color:var(--weline-theme-text,inherit);font:inherit;text-align:start;cursor:pointer;}'
            + '.w-address__menu[data-multi-menu] .w-address__option:hover,.w-address__menu[data-multi-menu] .w-address__option.is-active{background:var(--weline-theme-primary-subtle,var(--weline-theme-surface-hover,#eff4ff));color:var(--weline-theme-primary-text-emphasis,var(--weline-theme-primary,#1d4ed8));}'
            + '.w-address__menu[data-multi-menu] .w-address__empty,.w-address__menu[data-multi-menu] .w-address__menu-hint{padding:10px;color:var(--weline-theme-text-muted,#667085);font-size:var(--weline-layout-font-size-sm,13px);}'
            + '.w-address__menu[data-multi-menu] .w-address__group-label{position:sticky;top:0;z-index:1;padding:6px 10px 4px;margin:0;font-size:12px;font-weight:600;letter-spacing:.02em;color:var(--weline-theme-text-muted,#667085);background:color-mix(in srgb,var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff)) 92%,var(--weline-theme-primary,#333) 8%);border-bottom:1px solid color-mix(in srgb,var(--weline-theme-border-color,#d0d5dd) 70%,transparent);}'
            + '.w-address__menu[data-multi-menu].has-group-jump .w-address__group-label{position:static;top:auto;}'
            + '.w-address__menu[data-multi-menu] .w-address__group-jump{position:sticky;top:0;z-index:3;display:flex;flex-wrap:wrap;gap:4px;padding:4px 4px 8px;margin:0 0 2px;background:var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff));border-bottom:1px solid color-mix(in srgb,var(--weline-theme-border-color,#d0d5dd) 70%,transparent);}'
            + '.w-address__menu[data-multi-menu] .w-address__group-jump-chip{appearance:none;border:1px solid color-mix(in srgb,var(--weline-theme-border-color,#d0d5dd) 80%,transparent);border-radius:999px;padding:2px 8px;background:transparent;color:var(--weline-theme-text-muted,#667085);font:inherit;font-size:12px;line-height:1.4;cursor:pointer;}'
            + '.w-address__menu[data-multi-menu] .w-address__group-jump-chip:hover,.w-address__menu[data-multi-menu] .w-address__group-jump-chip.is-active{border-color:var(--weline-theme-primary,#2563eb);color:var(--weline-theme-primary-text-emphasis,var(--weline-theme-primary,#1d4ed8));background:var(--weline-theme-primary-subtle,rgba(37,99,235,.08));}'
            + '.w-address__menu[data-multi-menu] .w-address__group-empty{padding:8px 10px 10px;color:var(--weline-theme-text-muted,#667085);font-size:var(--weline-layout-font-size-sm,13px);}'
            + '</style>'
            + '<div class="w-address__multi">'
            + '<div class="w-address__multi-chips" data-multi-chips></div>'
            + '<div class="w-address w-address--multi">'
            + multiLevels.map(function (level) {
                return '<div class="w-field w-address__item" data-multi-panel="' + level + '" data-w-placement="bottom-start">'
                    + '<label class="w-field__label">' + escapeHtml(labels[level] || level) + '</label>'
                    + '<div class="w-field__control w-address__control">'
                    + '<input class="w-input w-address__input" type="search" autocomplete="off" data-multi-search="' + level + '"'
                    + ' placeholder="' + escapeHtml(searchPlaceholder[level] || labels.loading || '…') + '">'
                    + '<span class="w-address__arrow">\u25be</span>'
                    + '</div>'
                    + '<div class="w-address__menu" data-multi-menu="' + level + '" data-w-float-surface hidden></div>'
                    + '</div>';
            }).join('')
            + '</div></div>';

        var floatApis = {};
        var menusByLevel = {};
        multiLevels.forEach(function (level) {
            menusByLevel[level] = root.querySelector('[data-multi-menu="' + level + '"]');
        });

        function menuForLevel(level) {
            return menusByLevel[level] || null;
        }

        function uiFloating() {
            return window.Weline && window.Weline.UI && window.Weline.UI.floating
                ? window.Weline.UI.floating
                : null;
        }

        function syncMenuWidth(panel) {
            var level = panel.getAttribute('data-multi-panel') || '';
            var menu = menuForLevel(level);
            var control = panel.querySelector('.w-address__control');
            if (!menu || !control) {
                return;
            }
            var width = Math.round(control.getBoundingClientRect().width);
            if (width > 0) {
                menu.style.setProperty('--w-floating-inline-size', width + 'px');
                menu.style.minWidth = width + 'px';
            }
        }

        function ensureFloat(panel) {
            var level = panel.getAttribute('data-multi-panel') || '';
            if (floatApis[level]) {
                return floatApis[level];
            }
            var floating = uiFloating();
            var menu = menuForLevel(level);
            if (!floating || typeof floating.attach !== 'function' || !menu) {
                return null;
            }
            menu.setAttribute('data-w-float-surface', '');
            panel.setAttribute('data-w-placement', 'bottom-start');
            floatApis[level] = floating.attach(panel, {placement: 'bottom-start'});
            return floatApis[level];
        }

        function placeOpenMenu(panel) {
            if (!panel || !panel.classList.contains('is-open')) {
                return;
            }
            syncMenuWidth(panel);
            var api = ensureFloat(panel);
            if (api) {
                if (typeof api.show === 'function') {
                    api.show();
                } else if (typeof api.sync === 'function') {
                    api.sync();
                } else if (typeof api.place === 'function') {
                    api.place();
                }
                return;
            }
            // Fallback only when Weline.UI is unavailable (should not happen on Theme admin pages).
            var level = panel.getAttribute('data-multi-panel') || '';
            var menu = menuForLevel(level);
            if (menu) {
                menu.style.position = 'absolute';
                menu.style.top = 'calc(100% + 6px)';
                menu.style.insetInline = '0';
                menu.style.zIndex = '1080';
            }
        }

        function hideFloat(panel) {
            var level = panel.getAttribute('data-multi-panel') || '';
            var api = floatApis[level];
            if (api && typeof api.hide === 'function') {
                api.hide();
            }
            var menu = menuForLevel(level);
            if (menu && menu.style) {
                menu.style.position = '';
                menu.style.top = '';
                menu.style.insetInline = '';
                menu.style.zIndex = '';
                menu.style.minWidth = '';
                menu.style.removeProperty('--w-floating-inline-size');
            }
        }

        function emit() {
            var json = JSON.stringify(selected);
            root.setAttribute('data-multi-selection', json);
            root.dispatchEvent(new CustomEvent('weline:address:multi-change', {
                bubbles: true,
                detail: {selection: selected, code: code}
            }));
        }

        function chipLabel(row) {
            var type = text(row.region_type || '');
            var name = text(row.region_name || row.label || row.region_code || row.country_code || '');
            var cc = text(row.country_code).toUpperCase();
            if (type === 'province' && cc && name.indexOf(cc + ' / ') !== 0) {
                name = cc + ' / ' + name;
            } else if ((type === 'district' || type === 'city') && text(row.group_label)) {
                name = text(row.group_label) + ' / ' + text(row.region_name || row.label || name);
            }
            return (typeLabels[type] || type) + '：' + name;
        }

        function renderChips() {
            var chips = root.querySelector('[data-multi-chips]');
            if (!chips) {
                return;
            }
            if (!selected.length) {
                chips.innerHTML = '<span class="w-text" data-tone="muted">' + escapeHtml(labels.multiHint || '尚未选择，请搜索后添加') + '</span>';
                return;
            }
            chips.innerHTML = selected.map(function (row, index) {
                return '<button type="button" class="w-badge" data-tone="primary" data-multi-remove="' + index + '">'
                    + escapeHtml(chipLabel(row)) + ' ×</button>';
            }).join(' ');
            chips.querySelectorAll('[data-multi-remove]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    selected.splice(Number(btn.getAttribute('data-multi-remove')), 1);
                    emit();
                    renderChips();
                    refreshPools().then(function () {
                        repaintOpenMenus();
                    });
                });
            });
        }

        function isSelected(row) {
            var key = selectionKey(row);
            return selected.some(function (item) {
                return selectionKey(item) === key;
            });
        }

        function toggleRow(row) {
            var key = selectionKey(row);
            var idx = -1;
            selected.forEach(function (item, i) {
                if (selectionKey(item) === key) {
                    idx = i;
                }
            });
            if (idx >= 0) {
                selected.splice(idx, 1);
            } else {
                selected.push(row);
            }
            emit();
            renderChips();
            refreshPools().then(function () {
                repaintOpenMenus();
            });
        }

        function selectedCountries() {
            return selected.filter(function (row) {
                return text(row.region_type) === 'country';
            });
        }

        function selectedProvinces() {
            return selected.filter(function (row) {
                return text(row.region_type) === 'province';
            });
        }

        function selectedDistricts() {
            return selected.filter(function (row) {
                return text(row.region_type) === 'district' || text(row.region_type) === 'city';
            });
        }

        function rowFromRegion(region, typeForce) {
            var type = text(typeForce || region.region_type || 'country');
            var country = text(region.country_code || region.region_code || '').toUpperCase();
            if (type === 'country') {
                country = text(region.country_code || region.region_code || region.region_name || '').toUpperCase();
                if (country.length > 2 && region.country_code) {
                    country = text(region.country_code).toUpperCase();
                }
            }
            return {
                region_type: type,
                country_code: country.length === 2 ? country : text(region.country_code || '').toUpperCase(),
                region_id: type === 'country' ? null : (Number(region.region_id || 0) || null),
                region_code: type === 'country'
                    ? (country.length === 2 ? country : text(region.region_code || ''))
                    : text(region.region_code || ''),
                region_name: text(region.region_name || region.name || ''),
                label: text(region.region_name || region.name || region.region_code || country),
                street_id: null,
                parent_region_id: Number(region.parent_region_id || 0) || 0,
                sort_order: Number(region.sort_order || 0) || 0
            };
        }

        function filterItems(items, query, level) {
            query = text(query).trim().toLowerCase();
            var list = items || [];
            // 国家空搜展示全部分组（~249）；省/区仍用 200 预览上限。
            var previewLimit = level === 'country' ? list.length : 200;
            var searchLimit = level === 'country' ? 300 : 200;
            if (!query) {
                return list.slice(0, previewLimit || list.length);
            }
            return list.filter(function (item) {
                var hay = [
                    item.label,
                    item.region_name,
                    item.region_code,
                    item.country_code,
                    item.group_label,
                    item.province_name,
                    item.continent_label
                ].join(' ').toLowerCase();
                return hay.indexOf(query) > -1;
            }).slice(0, searchLimit);
        }

        function poolFilterHint(poolSize) {
            var template = labels.poolCountHint || labels.typeToFilter || '';
            if (template.indexOf('{n}') > -1) {
                return template.split('{n}').join(String(poolSize));
            }
            return template ? (template + ' · ' + poolSize) : String(poolSize);
        }

        function emptyHintFor(level) {
            if (poolHints[level]) {
                return poolHints[level];
            }
            if (level === 'province') {
                return selectedCountries().length
                    ? (labels.empty || '')
                    : (labels.selectCountryFirst || labels.empty || '');
            }
            if (level === 'city' || level === 'district') {
                return selectedProvinces().length
                    ? (labels.empty || '')
                    : (labels.selectProvinceFirst || labels.empty || '');
            }
            if (level === 'street') {
                return selectedDistricts().length
                    ? (labels.empty || '')
                    : (labels.selectDistrictFirst || labels.selectProvinceFirst || labels.empty || '');
            }
            return labels.empty || '';
        }

        function resolveCountryTitle(cc) {
            cc = text(cc).toUpperCase();
            if (!cc) {
                return '';
            }
            var sources = (optionPools.country || []).concat(selectedCountries());
            for (var i = 0; i < sources.length; i++) {
                if (text(sources[i].country_code).toUpperCase() !== cc) {
                    continue;
                }
                var name = text(sources[i].region_name || sources[i].label || '');
                if (name && name.toUpperCase() !== cc && name.indexOf(cc + ' / ') !== 0) {
                    return name + ' (' + cc + ')';
                }
            }
            return cc;
        }

        function cleanProvinceName(province) {
            var cc = text(province.country_code).toUpperCase();
            var name = text(province.region_name || province.label || province.region_code || '');
            if (cc && name.indexOf(cc + ' / ') === 0) {
                name = name.slice(cc.length + 3);
            }
            return name;
        }

        function optionDisplayLabel(level, hit) {
            if (level === 'province' || level === 'district' || level === 'city') {
                return text(hit.region_name || hit.label || hit.region_code || hit.country_code || '');
            }
            return text(hit.label || hit.region_name || hit.region_code || hit.country_code || '');
        }

        function groupLabelForHit(level, hit) {
            if (level === 'province') {
                return resolveCountryTitle(hit.country_code) || text(hit.group_label || hit.country_code);
            }
            if (level === 'district' || level === 'city') {
                return text(hit.group_label)
                    || ((text(hit.country_code).toUpperCase() + ' / ' + text(hit.province_name || '')).replace(/\s+\/\s+$/, ''))
                    || text(hit.country_code);
            }
            return '';
        }

        function buildGroupedHits(level, hits, query) {
            if (level === 'country') {
                return groupCountryEntriesByContinent(
                    (hits || []).map(function (hit, index) {
                        return {hit: hit, index: index};
                    }),
                    labels,
                    {includePopular: true}
                );
            }
            if (level !== 'province' && level !== 'district' && level !== 'city') {
                return [{label: '', entries: hits.map(function (hit, index) {
                    return {hit: hit, index: index};
                })}];
            }
            var buckets = {};
            var order = [];

            function ensureGroup(label) {
                label = text(label);
                if (!label) {
                    return;
                }
                if (!buckets[label]) {
                    buckets[label] = [];
                    order.push(label);
                }
            }

            // 已选上级一律占位，即使该上级暂无下级（如 AI 无省份）。
            if (level === 'province') {
                selectedCountries().forEach(function (country) {
                    var cc = text(country.country_code).toUpperCase();
                    ensureGroup(resolveCountryTitle(cc) || text(country.region_name || country.label || cc));
                });
            } else if (level === 'district' || level === 'city') {
                selectedProvinces().forEach(function (province) {
                    var cc = text(province.country_code).toUpperCase();
                    var pname = cleanProvinceName(province);
                    // 与 loadDistrictPoolForProvince 的 group_label 一致（勿用省份池的国家 group_label）
                    ensureGroup((cc ? cc + ' / ' : '') + pname);
                });
            }

            hits.forEach(function (hit, index) {
                var label = groupLabelForHit(level, hit) || '—';
                ensureGroup(label);
                buckets[label].push({hit: hit, index: index});
            });
            return order.map(function (label) {
                return {label: label, entries: buckets[label] || []};
            });
        }

        function paintMenu(level, query) {
            var menu = menuForLevel(level);
            var item = root.querySelector('[data-multi-panel="' + level + '"]');
            if (!menu || !item) {
                return;
            }
            var pool = optionPools[level] || [];
            var needle = text(query).trim();
            var parentAware = level === 'province' || level === 'district' || level === 'city';
            var parentCount = level === 'province'
                ? selectedCountries().length
                : ((level === 'district' || level === 'city') ? selectedProvinces().length : 0);

            if (!pool.length && !(parentAware && parentCount)) {
                menu.innerHTML = '<div class="w-address__empty">' + escapeHtml(emptyHintFor(level)) + '</div>';
                menu.classList.remove('has-group-jump');
                if (item.classList.contains('is-open')) {
                    placeOpenMenu(item);
                }
                return;
            }

            var hits = filterItems(pool, needle, level);
            var html = '';
            if (!needle && (pool.length || parentCount)) {
                html += '<div class="w-address__menu-hint">' + escapeHtml(poolFilterHint(pool.length)) + '</div>';
            } else if (needle && pool.length > hits.length) {
                html += '<div class="w-address__menu-hint">' + escapeHtml(poolFilterHint(pool.length)) + '</div>';
            }

            var sections = assignGroupAnchors(buildGroupedHits(level, hits, needle));
            if (!hits.length && !(parentAware && sections.some(function (section) {
                return text(section.label);
            }))) {
                menu.innerHTML = html + '<div class="w-address__empty">' + escapeHtml(labels.empty || '') + '</div>';
                menu.classList.remove('has-group-jump');
                if (item.classList.contains('is-open')) {
                    placeOpenMenu(item);
                }
                return;
            }

            html += buildGroupJumpHtml(sections);
            sections.forEach(function (groupSection) {
                if (groupSection.label) {
                    html += '<div class="w-address__group-label" data-group-anchor="' + escapeHtml(String(groupSection.anchorId)) + '">'
                        + escapeHtml(groupSection.label) + '</div>';
                }
                if (!(groupSection.entries && groupSection.entries.length)) {
                    html += '<div class="w-address__group-empty">' + escapeHtml(labels.noChildren || labels.empty || '') + '</div>';
                    return;
                }
                groupSection.entries.forEach(function (entry) {
                    var hit = entry.hit;
                    var active = isSelected(hit) ? ' is-active' : '';
                    var mark = isSelected(hit) ? ' ✓' : '';
                    html += '<button type="button" class="w-address__option' + active + '" data-multi-pick="' + entry.index + '">'
                        + escapeHtml(optionDisplayLabel(level, hit))
                        + mark + '</button>';
                });
            });
            menu.innerHTML = html;
            bindGroupJumpNavigation(menu);
            menu.querySelectorAll('[data-multi-pick]').forEach(function (btn) {
                btn.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    var hit = hits[Number(btn.getAttribute('data-multi-pick'))];
                    if (hit) {
                        toggleRow(hit);
                        var input = root.querySelector('[data-multi-search="' + level + '"]');
                        if (input) {
                            input.value = '';
                            input.focus();
                        }
                        paintMenu(level, '');
                    }
                });
            });
            if (item.classList.contains('is-open')) {
                placeOpenMenu(item);
            }
        }

        function openMenu(level) {
            root.querySelectorAll('[data-multi-panel]').forEach(function (panel) {
                var open = panel.getAttribute('data-multi-panel') === level;
                panel.classList.toggle('is-open', open);
                var menu = menuForLevel(panel.getAttribute('data-multi-panel') || '');
                if (menu) {
                    if (open) {
                        menu.removeAttribute('hidden');
                    } else {
                        menu.setAttribute('hidden', 'hidden');
                        hideFloat(panel);
                    }
                }
            });
            var needle = text((root.querySelector('[data-multi-search="' + level + '"]') || {}).value || '');
            var openPanel = root.querySelector('[data-multi-panel="' + level + '"]');
            // Defer region.children / embargo_* until the user actually opens a menu.
            // Website/store/channel forms embed collapsed shipping address multis — eager refreshPools stampeded BinQuery on page load.
            ensurePools().then(function () {
                paintMenu(level, needle);
                if (openPanel) {
                    placeOpenMenu(openPanel);
                }
            });
        }

        var poolsPromise = null;
        function ensurePools() {
            if (poolsPromise) {
                return poolsPromise;
            }
            poolsPromise = refreshPools().then(function () {
                if (root.querySelector('[data-multi-panel].is-open')) {
                    repaintOpenMenus();
                }
            }).catch(function () {
                return null;
            });
            return poolsPromise;
        }

        function closeMenus() {
            root.querySelectorAll('[data-multi-panel]').forEach(function (panel) {
                panel.classList.remove('is-open');
                var menu = menuForLevel(panel.getAttribute('data-multi-panel') || '');
                if (menu) {
                    menu.setAttribute('hidden', 'hidden');
                }
                hideFloat(panel);
            });
        }

        function repaintOpenMenus() {
            root.querySelectorAll('[data-multi-panel].is-open').forEach(function (panel) {
                var level = panel.getAttribute('data-multi-panel');
                paintMenu(level, text((root.querySelector('[data-multi-search="' + level + '"]') || {}).value || ''));
                placeOpenMenu(panel);
            });
        }

        function decorateLocalityItem(item, cc, parentId, provinceName, groupLabel) {
            item.country_code = cc;
            item.province_region_id = parentId;
            item.province_name = provinceName;
            item.group_label = groupLabel;
            item.parent_region_id = parentId;
            item.label = text(item.region_name || item.label || item.region_code || '');
            return item;
        }

        function citiesAsLeafLocalities(cities, cc, parentId, provinceName, groupLabel) {
            return (cities || []).map(function (city) {
                return decorateLocalityItem(rowFromRegion(city, 'city'), cc, parentId, provinceName, groupLabel);
            });
        }

        function loadDistrictPoolForProvince(province) {
            var parentId = Number(province.region_id || 0);
            var cc = text(province.country_code).toUpperCase();
            var provinceName = cleanProvinceName(province);
            var groupLabel = (cc ? cc + ' / ' : '') + provinceName;
            if (!parentId) {
                return Promise.resolve([]);
            }
            return loadChildren(parentId, '', 5000).then(function (rows) {
                rows = Array.isArray(rows) ? rows : [];
                var direct = [];
                var cities = [];
                rows.forEach(function (row) {
                    var type = text(row.region_type);
                    if (type === 'district' || type === 'county') {
                        direct.push(decorateLocalityItem(
                            rowFromRegion(row, 'district'),
                            cc,
                            parentId,
                            provinceName,
                            groupLabel
                        ));
                    } else if (type === 'city' || type === '') {
                        cities.push(row);
                    }
                });
                if (direct.length && !cities.length) {
                    return direct;
                }
                if (!cities.length) {
                    return direct;
                }
                // AU 等 profile 仅到 city：省下级即叶子，勿再按市钻区县（否则区县池为空）。
                return loadCountryProfile(cc).then(function (profile) {
                    var levels = profile && Array.isArray(profile.levels) ? profile.levels : [];
                    var profileHasDistrict = levels.indexOf('district') > -1 || levels.indexOf('county') > -1;
                    if (!profileHasDistrict) {
                        return direct.concat(citiesAsLeafLocalities(cities, cc, parentId, provinceName, groupLabel));
                    }
                    var probes = cities.slice(0, 5);
                    return Promise.all(probes.map(function (city) {
                        var cityId = Number(city.region_id || 0);
                        if (!cityId) {
                            return [];
                        }
                        return loadChildren(cityId, '', 20).then(function (districts) {
                            return (Array.isArray(districts) ? districts : []).filter(function (row) {
                                var type = text(row.region_type);
                                return type === 'district' || type === 'county' || type === '';
                            });
                        }).catch(function () {
                            return [];
                        });
                    })).then(function (probeChunks) {
                        var anyDistrict = probeChunks.some(function (chunk) {
                            return chunk && chunk.length;
                        });
                        if (!anyDistrict) {
                            return direct.concat(citiesAsLeafLocalities(cities, cc, parentId, provinceName, groupLabel));
                        }
                        // CN 等：省→市→区；限制并发以免一次打爆接口。
                        var citySlice = cities.slice(0, 80);
                        return Promise.all(citySlice.map(function (city) {
                            var cityId = Number(city.region_id || 0);
                            if (!cityId) {
                                return [];
                            }
                            var cityName = text(city.region_name || city.name || '');
                            return loadChildren(cityId, '', 800).then(function (districts) {
                                return (Array.isArray(districts) ? districts : []).filter(function (row) {
                                    var type = text(row.region_type);
                                    return type === 'district' || type === 'county' || type === '';
                                }).map(function (row) {
                                    var item = decorateLocalityItem(
                                        rowFromRegion(row, 'district'),
                                        cc,
                                        parentId,
                                        provinceName,
                                        groupLabel
                                    );
                                    item.city_name = cityName;
                                    item.label = (cityName ? cityName + ' / ' : '') + text(item.region_name || item.label);
                                    return item;
                                });
                            }).catch(function () {
                                return [];
                            });
                        })).then(function (chunks) {
                            var items = direct.slice();
                            chunks.forEach(function (chunk) {
                                items = items.concat(chunk);
                            });
                            return items;
                        });
                    });
                });
            }).catch(function () {
                return [];
            });
        }

        function enrichSelectedLabels() {
            selected.forEach(function (row) {
                var type = text(row.region_type || '');
                var pool = optionPools[type] || [];
                if (!pool.length) {
                    return;
                }
                var key = selectionKey(row);
                var cc = text(row.country_code).toUpperCase();
                pool.forEach(function (item) {
                    var matched = false;
                    if (type === 'country') {
                        matched = text(item.country_code).toUpperCase() === cc;
                    } else {
                        matched = selectionKey(item) === key;
                    }
                    if (matched && text(item.label || item.region_name)) {
                        row.label = text(item.label || item.region_name);
                        row.region_name = text(item.region_name || item.label || row.region_name || '');
                        if (type === 'country') {
                            row.region_code = text(item.region_code || row.region_code || cc);
                        }
                        if (!row.region_id && item.region_id) {
                            row.region_id = item.region_id;
                        }
                    }
                });
            });
        }

        function refreshPools() {
            var tasks = [];
            if (multiLevels.indexOf('country') > -1) {
                poolHints.country = '';
                tasks.push(loadRegions(sourceUrl, '', catalog).then(function (rows) {
                    var items = (Array.isArray(rows) ? rows : []).filter(function (row) {
                        return text(row.region_type) === 'country';
                    }).map(function (row) {
                        var item = rowFromRegion(row, 'country');
                        item.continent_key = commerceContinentKey(item.country_code);
                        item.continent_label = commerceContinentLabel(item.continent_key, labels);
                        item.group_label = item.continent_label;
                        return item;
                    }).filter(function (row) {
                        return /^[A-Z]{2}$/.test(row.country_code);
                    });
                    var keyRank = {};
                    commerceContinentOrder.forEach(function (key, idx) {
                        keyRank[key] = idx;
                    });
                    items.sort(function (a, b) {
                        var sa = Number(a.sort_order || 0) || 0;
                        var sb = Number(b.sort_order || 0) || 0;
                        // Non-hot countries stay after seeded hot ranks (<9000).
                        var aHot = sa > 0 && sa < 9000 ? sa : 9000 + sa;
                        var bHot = sb > 0 && sb < 9000 ? sb : 9000 + sb;
                        if (aHot !== bHot) {
                            return aHot - bHot;
                        }
                        var ra = keyRank[a.continent_key] || 99;
                        var rb = keyRank[b.continent_key] || 99;
                        if (ra !== rb) {
                            return ra - rb;
                        }
                        return text(a.region_name || a.label).localeCompare(text(b.region_name || b.label), 'zh');
                    });
                    optionPools.country = items;
                    enrichSelectedLabels();
                    renderChips();
                    emit();
                }));
            }
            if (multiLevels.indexOf('province') > -1) {
                var countries = selectedCountries();
                if (!countries.length) {
                    optionPools.province = [];
                    poolHints.province = labels.selectCountryFirst || labels.empty || '';
                } else {
                    poolHints.province = '';
                    tasks.push(Promise.all(countries.map(function (country) {
                        var cc = text(country.country_code).toUpperCase();
                        var countryTitle = resolveCountryTitle(cc) || text(country.region_name || country.label || cc);
                        return loadChildren(null, cc, 500).then(function (rows) {
                            return (Array.isArray(rows) ? rows : [])
                                .filter(function (row) {
                                    return text(row.region_type) === 'province' || !row.region_type;
                                })
                                .map(function (row) {
                                    var item = rowFromRegion(row, 'province');
                                    item.country_code = cc;
                                    item.group_label = countryTitle;
                                    item.label = text(item.region_name || item.label);
                                    return item;
                                });
                        }).catch(function () {
                            return [];
                        });
                    })).then(function (chunks) {
                        var items = [];
                        chunks.forEach(function (chunk) {
                            items = items.concat(chunk);
                        });
                        items.sort(function (a, b) {
                            var ca = text(a.country_code);
                            var cb = text(b.country_code);
                            if (ca !== cb) {
                                return ca < cb ? -1 : 1;
                            }
                            return text(a.region_name || a.label).localeCompare(text(b.region_name || b.label), 'zh');
                        });
                        optionPools.province = items;
                        enrichSelectedLabels();
                    }));
                }
            }
            if (multiLevels.indexOf('city') > -1 && multiLevels.indexOf('district') === -1) {
                var cityParents = selectedProvinces();
                if (!cityParents.length) {
                    optionPools.city = [];
                    poolHints.city = labels.selectProvinceFirst || labels.empty || '';
                } else {
                    poolHints.city = '';
                    tasks.push(Promise.all(cityParents.map(function (province) {
                        var parentId = Number(province.region_id || 0);
                        var cc = text(province.country_code).toUpperCase();
                        if (!parentId) {
                            return [];
                        }
                        return loadChildren(parentId, '', 2000).then(function (rows) {
                            return (Array.isArray(rows) ? rows : []).filter(function (row) {
                                return text(row.region_type) === 'city' || text(row.region_type) === '';
                            }).map(function (row) {
                                var item = rowFromRegion(row, 'city');
                                item.country_code = cc;
                                item.label = text(province.label || province.region_name || cc) + ' / ' + text(item.label);
                                return item;
                            });
                        }).catch(function () {
                            return [];
                        });
                    })).then(function (chunks) {
                        var items = [];
                        chunks.forEach(function (chunk) {
                            items = items.concat(chunk);
                        });
                        optionPools.city = items;
                    }));
                }
            }
            if (multiLevels.indexOf('district') > -1) {
                var parents = selectedProvinces();
                if (!parents.length) {
                    optionPools.district = [];
                    poolHints.district = labels.selectProvinceFirst || labels.empty || '';
                } else {
                    poolHints.district = labels.loading || '加载中…';
                    tasks.push(Promise.all(parents.map(loadDistrictPoolForProvince)).then(function (chunks) {
                        var items = [];
                        chunks.forEach(function (chunk) {
                            items = items.concat(chunk);
                        });
                        items.sort(function (a, b) {
                            var ga = text(a.group_label || a.country_code);
                            var gb = text(b.group_label || b.country_code);
                            if (ga !== gb) {
                                return ga < gb ? -1 : 1;
                            }
                            return text(a.region_name || a.label).localeCompare(text(b.region_name || b.label), 'zh');
                        });
                        optionPools.district = items;
                        poolHints.district = items.length ? '' : (labels.empty || '');
                    }));
                }
            }
            if (multiLevels.indexOf('street') > -1) {
                var streetParents = selectedDistricts();
                if (!streetParents.length) {
                    optionPools.street = [];
                    poolHints.street = labels.selectDistrictFirst || labels.selectProvinceFirst || labels.empty || '';
                } else {
                    poolHints.street = '';
                    tasks.push(Promise.all(streetParents.map(function (district) {
                        var parentId = Number(district.region_id || 0);
                        var cc = text(district.country_code).toUpperCase();
                        if (!parentId) {
                            return [];
                        }
                        return loadChildren(parentId, '', 800).then(function (rows) {
                            return (Array.isArray(rows) ? rows : []).filter(function (row) {
                                var type = text(row.region_type);
                                return type === 'street' || type === '';
                            }).map(function (row) {
                                var item = rowFromRegion(row, 'street');
                                item.country_code = cc;
                                item.label = text(district.label || district.region_name || '') + ' / ' + text(item.label);
                                return item;
                            });
                        }).catch(function () {
                            return [];
                        });
                    })).then(function (chunks) {
                        var items = [];
                        chunks.forEach(function (chunk) {
                            items = items.concat(chunk);
                        });
                        optionPools.street = items;
                    }));
                }
            }
            return Promise.all(tasks).then(function () {
                enrichSelectedLabels();
                renderChips();
                emit();
            }).catch(function () {
                enrichSelectedLabels();
                renderChips();
                emit();
            });
        }

        multiLevels.forEach(function (level) {
            var input = root.querySelector('[data-multi-search="' + level + '"]');
            if (!input) {
                return;
            }
            input.addEventListener('focus', function () {
                openMenu(level);
            });
            // 已聚焦时再次点击也要打开（否则像“点了没反应”）
            input.addEventListener('click', function () {
                openMenu(level);
            });
            input.addEventListener('input', function () {
                openMenu(level);
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeMenus();
                    input.blur();
                }
            });
        });
        document.addEventListener('click', function (event) {
            var target = event.target;
            if (root.contains(target)) {
                return;
            }
            var hitPortaledMenu = false;
            Object.keys(menusByLevel).forEach(function (level) {
                var menu = menusByLevel[level];
                if (menu && menu.contains(target)) {
                    hitPortaledMenu = true;
                }
            });
            if (!hitPortaledMenu) {
                closeMenus();
            }
        });

        renderChips();
        emit();
        // Do not refreshPools() on mount — wait for ensurePools() via first openMenu.
    }

    function ensureAddressBaseStyles() {
        if (typeof document === 'undefined') {
            return;
        }
        // Backend admin does not load frontend theme.css; single-select must self-contain
        // menu absolute/hide rules (multi already injects its own). Refresh when token changes.
        var styleToken = 'notch-md-20260910b';
        var style = document.querySelector('style[data-w-address-base-style]');
        if (style && style.getAttribute('data-w-address-base-style') === styleToken) {
            return;
        }
        if (!style) {
            style = document.createElement('style');
            (document.head || document.documentElement).appendChild(style);
        }
        style.setAttribute('data-w-address-base-style', styleToken);
        style.textContent = ''
            + '.w-address{font-family:inherit;color:var(--weline-theme-text,inherit);--weline-theme-field-label-bg:var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff));}'
            + '.w-address--single{display:block;}'
            + '.w-address:not(.w-address--single){display:grid;gap:var(--weline-layout-spacing-md,12px);}'
            + '.w-address__item{position:relative;min-width:0;}'
            + '.w-address__item.w-field{gap:0;}'
            + '.w-address__item > .w-field__label{font-weight:var(--weline-layout-font-weight-semibold,600);}'
            + '.w-address__control{position:relative;display:flex;align-items:center;min-height:var(--weline-theme-control-height,40px);border:var(--weline-theme-border-width,1px) var(--weline-theme-border-style,solid) var(--weline-theme-border-color,#d0d5dd);border-radius:var(--weline-radius-md,var(--weline-theme-radius-md,8px));background:var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff));box-shadow:var(--theme-input-shadow,none);color:var(--weline-theme-text,inherit);transition:border-color .16s ease,box-shadow .16s ease,background .16s ease;}'
            + '.w-address__control:focus-within{border-color:var(--weline-theme-primary,var(--weline-theme-focus-outline,#2563eb));box-shadow:var(--weline-theme-focus-ring,0 0 0 3px var(--weline-theme-focus-ring-color,rgba(37,99,235,.15)));}'
            + '.w-address__control.is-disabled{background:var(--weline-theme-surface-subtle,#f2f4f7);color:var(--weline-theme-text-muted,#98a2b3);cursor:not-allowed;}'
            + '.w-address__control .w-input,.w-address__input{width:100%;min-width:0;height:var(--weline-component-control-height,var(--weline-theme-control-height,40px));min-height:0;padding:0 28px 0 12px;border:0;outline:0;box-shadow:none;background:transparent;color:var(--weline-theme-text,inherit);caret-color:var(--weline-theme-text,currentColor);font:inherit;}'
            + '.w-address__control .w-input::placeholder,.w-address__input::placeholder{color:var(--weline-theme-text-muted,#98a2b3);}'
            + '.w-address__arrow{position:absolute;inset-inline-end:12px;color:var(--weline-theme-text-muted,#98a2b3);font-size:12px;pointer-events:none;}'
            + '.w-address__menu{position:absolute;z-index:var(--weline-z-menu,1080);top:calc(100% + 6px);inset-inline:0;display:none;box-sizing:border-box;max-height:min(260px,70vh);overflow:auto;padding:6px;border:var(--weline-theme-border-width,1px) var(--weline-theme-border-style,solid) var(--weline-theme-border-color,#d0d5dd);border-radius:var(--weline-radius-md,var(--weline-theme-radius-md,8px));background:var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff));color:var(--weline-theme-text,inherit);box-shadow:var(--weline-theme-shadow-lg,0 12px 32px rgba(16,24,40,.14));}'
            + '.w-address__menu[data-w-float-surface],.w-address__menu[data-multi-menu],.w-address__menu[data-w-floating-portal],.w-address__menu[data-w-floating-positioned]{position:fixed;inset:auto;top:auto;right:auto;bottom:auto;left:auto;inset-inline:auto;margin:0;}'
            + '.w-address__menu[data-multi-menu]:not([hidden]),.w-address__menu[data-w-float-surface]:not([hidden]){display:block;}'
            + '.w-address__menu[data-w-floating-positioned]{top:max(var(--w-floating-top,0px),var(--w-floating-viewport-top,.5rem));left:max(var(--w-floating-left,0px),var(--w-floating-viewport-left,.5rem));inline-size:var(--w-floating-inline-size,auto);max-inline-size:min(var(--w-floating-max-inline-size,calc(100dvw - 1rem)),calc(var(--w-floating-viewport-right,calc(100dvw - .5rem)) - max(var(--w-floating-left,0px),var(--w-floating-viewport-left,.5rem))));max-block-size:min(260px,var(--w-floating-max-block-size,70vh),calc(var(--w-floating-viewport-bottom,calc(100dvh - .5rem)) - max(var(--w-floating-top,0px),var(--w-floating-viewport-top,.5rem))));transform-origin:var(--w-floating-transform-origin,top);}'
            + '.w-address__menu[data-w-floating-positioned="pending"]{visibility:hidden;}'
            + '.w-address__item.is-open .w-address__menu{display:block;}'
            + '.w-address__menu .w-address__group-label{position:sticky;top:0;z-index:1;padding:6px 10px 4px;margin:0;font-size:12px;font-weight:600;letter-spacing:.02em;color:var(--weline-theme-text-muted,#667085);background:color-mix(in srgb,var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff)) 92%,var(--weline-theme-primary,#333) 8%);border-bottom:1px solid color-mix(in srgb,var(--weline-theme-border-color,#d0d5dd) 70%,transparent);}'
            + '.w-address__menu.has-group-jump .w-address__group-label{position:static;top:auto;}'
            + '.w-address__menu .w-address__group-jump{position:sticky;top:0;z-index:3;display:flex;flex-wrap:wrap;gap:4px;padding:4px 4px 8px;margin:0 0 2px;background:var(--weline-theme-surface,var(--weline-theme-surface-raised,#fff));border-bottom:1px solid color-mix(in srgb,var(--weline-theme-border-color,#d0d5dd) 70%,transparent);}'
            + '.w-address__menu .w-address__group-jump-chip{appearance:none;border:1px solid color-mix(in srgb,var(--weline-theme-border-color,#d0d5dd) 80%,transparent);border-radius:999px;padding:2px 8px;background:transparent;color:var(--weline-theme-text-muted,#667085);font:inherit;font-size:12px;line-height:1.4;cursor:pointer;}'
            + '.w-address__menu .w-address__group-jump-chip:hover,.w-address__menu .w-address__group-jump-chip.is-active{border-color:var(--weline-theme-primary,#2563eb);color:var(--weline-theme-primary-text-emphasis,var(--weline-theme-primary,#1d4ed8));background:var(--weline-theme-primary-subtle,rgba(37,99,235,.08));}'
            + '.w-address__option{display:block;width:100%;padding:8px 10px;border:0;border-radius:8px;background:transparent;color:var(--weline-theme-text,inherit);font:inherit;text-align:start;cursor:pointer;}'
            + '.w-address__option:hover,.w-address__option.is-active{background:var(--weline-theme-primary-subtle,var(--weline-theme-surface-hover,#eff4ff));color:var(--weline-theme-primary-text-emphasis,var(--weline-theme-primary,#1d4ed8));}'
            + '.w-address__empty,.w-address__menu-hint{padding:10px;color:var(--weline-theme-text-muted,#667085);font-size:var(--weline-layout-font-size-sm,13px);}'
            + '.w-address__menu .w-address__group-empty{padding:8px 10px 10px;color:var(--weline-theme-text-muted,#667085);font-size:var(--weline-layout-font-size-sm,13px);}';
    }

    function mount(root) {
        if (!root) {
            return;
        }
        ensureAddressBaseStyles();
        var existingCode = text(root.dataset.addressCode || '');
        if (root.dataset.wAddressReady === 'true' && existingCode && groups[existingCode]) {
            return;
        }
        // allow remount after module redefine when groups were wiped
        root.dataset.wAddressReady = '';
        var config = readConfig(root);
        if (text(config.selection || '') === 'multi') {
            mountMulti(root, config);
            return;
        }
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
        var countryOnly = levels.length === 1 && levels[0] === 'country';
        var catalog = text(config.catalog || root.getAttribute('data-catalog') || 'installed') === 'global'
            ? 'global'
            : 'installed';
        // Remounts that keep a visible country picker must not reuse a group whose regions became provinces.
        if ((countryOnly || (catalog === 'global' && levels.indexOf('country') > -1)) && groups[code]) {
            delete groups[code];
        }
        var group = groupFor(code, config.sourceUrl || defaultSourceUrl, catalog);
        group.cascade = config.cascade !== false;
        group.catalog = catalog;
        group.countryOnly = countryOnly;
        group.labels = labelsFor(config);
        group.sourceUrl = frontendRoute(config.sourceUrl || group.sourceUrl || defaultSourceUrl);
        group.metaPrefix = text(config.metaPrefix || config.meta_prefix || '');
        group.postalName = text(config.postalName || 'postal_code') || 'postal_code';
        group.config = config;
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
        // Keep cascade inside postal/detail shell (postal → cascade → detail).
        // Account form used to reparent into the 2-col grid; that tears the shell apart.
        if (!root.closest('[data-w-address-shell]')) {
            var anchor = firstField && firstField.closest && firstField.closest('.account-address-form__field');
            if (anchor && anchor.parentNode) {
                anchor.parentNode.insertBefore(root, anchor);
            }
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
            var target = event.target;
            if (root.contains(target)) {
                return;
            }
            var hitPortal = Object.keys(group.controls || {}).some(function (level) {
                var menu = group.controls[level] && group.controls[level].menu;
                return !!(menu && menu.contains && menu.contains(target));
            });
            if (!hitPortal) {
                closeMenus(group);
            }
        });
        updateGroup(group);
        bindPostalLookup(group, root);
        loadRegions(group.sourceUrl, '', group.catalog).then(function (regions) {
            group.regions = regions || [];
            // 异步加载会覆盖本地合成国家；按已锁定国家重新注入。
            ensureCountryInRegions(
                group,
                group.fixed.country || metadataValue(group, 'country_code'),
                metadataValue(group, 'country')
            );
            updateGroup(group);
            if (group.countryOnly) {
                return;
            }
            var lockedCountry = text(group.fixed.country || metadataValue(group, 'country_code')).toUpperCase();
            // Keep the global country list until a country is fixed/selected; subdivisions load via ensureLevelChildren.
            if (group.catalog === 'global' && group.controls.country && !text(group.fixed.country || '')) {
                return;
            }
            if (lockedCountry) {
                loadCountryProfile(lockedCountry).then(function (profile) {
                    applyCountryProfile(group, profile);
                    updateGroup(group);
                });
                return ensureCountryCatalog(group).then(function () {
                    return loadRegions(group.sourceUrl, lockedCountry, group.catalog).then(function (countryRegions) {
                        if (Array.isArray(countryRegions) && countryRegions.length) {
                            adoptRegionRows(group, countryRegions);
                            ensureCountryInRegions(group, lockedCountry, metadataValue(group, 'country'));
                            updateGroup(group);
                        }
                    });
                });
            }
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
        var hasCountryKey = Object.prototype.hasOwnProperty.call(values, 'country_code')
            || Object.prototype.hasOwnProperty.call(values, 'countryCode')
            || Object.prototype.hasOwnProperty.call(values, 'country');
        var hasLowerValues = !!(text(values.province || values.region || '').trim()
            || text(values.city || '').trim()
            || text(values.district || '').trim()
            || text(values.street || '').trim());
        var countryCodeField = metaFieldName(group, 'country_code');
        var countryNameField = metaFieldName(group, 'country');
        if (hasCountryKey && !countryCode && root) {
            // 显式清空国家（邮编多国待选），禁止 fixed.country 把「中国」写回
            group.fixed.country = '';
            group.state.country = null;
            findOrCreateField(root, form, countryCodeField).value = '';
            findOrCreateField(root, form, countryNameField).value = '';
            if (group.controls.country) {
                group.controls.country.field.value = '';
                if (group.controls.country.input) {
                    group.controls.country.input.value = '';
                }
            }
        } else if (countryCode && root) {
            findOrCreateField(root, form, countryCodeField).value = countryCode;
            findOrCreateField(root, form, countryNameField).value = countryName || countryCode;
            // 无国家控件时（宿主顶部已选国家）才锁定；有国家控件时禁止 fixed 锁死，
            // 否则国家菜单只剩当前国，系统禁运国无法再出现并标「不支持配送」。
            if (!group.controls.country) {
                group.fixed.country = countryCode;
            } else if (text(group.fixed.country).toUpperCase() === countryCode) {
                // 保持显式配置的 filters.country；不因 applyValues 额外加锁
            } else {
                group.fixed.country = '';
            }
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
            ['province', 'city', 'district', 'street'].forEach(function (level) {
                if (group.controls[level] && group.controls[level].field) {
                    group.controls[level].field.value = '';
                }
            });
        }
        setLevel('province', values.province || values.region);
        setLevel('city', values.city);
        setLevel('district', values.district);
        setLevel('street', values.street);

        function finish() {
            refreshState(group);
            // 无省市区数据时保持可手填；占位符在 updateGroup 中按 fixed.country 更新。
            syncMetadata(group);
            updateGroup(group);
            return true;
        }

        // 切国家时带 country_code 重新拉该国下级；有国家控件时合并进现有国家列表（禁运国须保留可见）。
        if (countryCode) {
            return ensureCountryCatalog(group).then(function () {
                return loadRegions(group.sourceUrl, countryCode, group.catalog).then(function (regions) {
                    adoptRegionRows(group, regions || []);
                    ensureCountryInRegions(group, countryCode, countryName || countryCode);
                    return loadChildren(null, countryCode, 500).then(function (rows) {
                        mergeRegions(group, rows);
                        return finish();
                    });
                });
            });
        }

        if (group.regions && group.regions.length) {
            return Promise.resolve(finish());
        }

        return loadRegions(group.sourceUrl, '', group.catalog).then(function (regions) {
            group.regions = regions || [];
            return finish();
        });
    }


    function findPostalFieldForRoot(root) {
        if (!root) {
            return null;
        }
        var postalName = '';
        try {
            var cfg = readConfig(root) || {};
            postalName = text(cfg.postalName || '').trim();
        } catch (e) {
            postalName = '';
        }
        var selectors = '[data-w-address-postal], [data-postal-first], [data-shipping-field][name="postal_code"], [data-billing-field][name="billing_postal_code"], [name="postal_code"]';
        if (postalName) {
            selectors += ', [name="' + postalName + '"]';
        }
        var shell = root.closest('[data-w-address-shell]');
        if (shell) {
            var inShell = shell.querySelector('[data-w-address-postal], [data-postal-first]');
            if (inShell) {
                return inShell;
            }
        }
        // Checkout: prefer the nearest shipping/billing section so dual postals do not cross-bind.
        var host = root.closest(
            '[data-billing-address-cascade], [data-shipping-address-cascade],'
            + ' [data-billing-editor], [data-address-editor],'
            + ' [data-billing-section], [data-shipping-section],'
            + ' [data-shipping-checkout-address], .w-shipping-checkout-address, form'
        );
        if (host) {
            if (postalName) {
                var named = host.querySelector('[name="' + postalName + '"]');
                if (named) {
                    return named;
                }
            }
            var inHost = host.querySelector(selectors);
            if (inHost) {
                return inHost;
            }
        }
        var parent = root.parentElement;
        if (parent) {
            var sibling = parent.querySelector(selectors);
            if (sibling) {
                return sibling;
            }
            if (parent.parentElement) {
                var uncle = parent.parentElement.querySelector(selectors);
                if (uncle) {
                    return uncle;
                }
            }
        }
        var form = root.closest('form');
        if (form) {
            if (postalName) {
                var formNamed = form.querySelector('[name="' + postalName + '"]');
                if (formNamed) {
                    return formNamed;
                }
            }
            return form.querySelector(selectors);
        }
        return null;
    }

    function postalLookupEnabled(root, config) {
        if (config && config.postalLookup) {
            return true;
        }
        if (root && root.getAttribute && root.getAttribute('data-postal-lookup') === '1') {
            return true;
        }
        return !!(root && root.closest && root.closest('[data-w-address-shell][data-postal-lookup="1"]'));
    }

    function bindPostalLookup(group, root) {
        if (!group || !root || group.__postalLookupBound) {
            return;
        }
        var config = readConfig(root) || {};
        if (!postalLookupEnabled(root, config)) {
            return;
        }
        group.__postalLookupBound = true;
        var POSTAL_LOOKUP_DEBOUNCE_MS = 500;
        var postalLookupTimer = null;
        var postalJobSeq = 0;
        var postalJobQueue = Promise.resolve();
        // Same postal must not re-hit postal_countries after debounce already enqueued it
        // (paste historically also fired change/blur → second lookup).
        var lastEnqueuedPostal = '';
        var code = group.code;
        var postalFieldSelector = '[data-w-address-postal], [data-postal-first], [data-shipping-field][name="postal_code"], [name="postal_code"]';

        function setLoading(busy) {
            setCascadeLoading(code, !!busy);
        }
        function finishJob(jobId) {
            if (jobId === postalJobSeq) {
                setLoading(false);
            }
        }
        function preservePostal(postal) {
            var field = findPostalFieldForRoot(root);
            if (field && text(field.value).trim() === postal) {
                field.value = postal;
            }
        }
        function clearCascade(countryCode, options) {
            options = options || {};
            var keepCountry = options.keepCountry !== false;
            var values = { province: '', city: '', district: '', street: '' };
            if (keepCountry) {
                values.country_code = countryCode || 'CN';
            } else {
                values.country_code = '';
                values.country = '';
            }
            return applyValues(code, values);
        }
        function applyHit(countryCode, postal, jobId) {
            group.pendingPostalCode = postal;
            return postalLookup(countryCode, postal, 8).then(function (rows) {
                if (jobId !== postalJobSeq) {
                    return null;
                }
                var field = findPostalFieldForRoot(root);
                var latest = text(field && field.value).trim();
                if (latest !== postal) {
                    return null;
                }
                if (!Array.isArray(rows) || !rows.length) {
                    return clearCascade(countryCode).then(function () {
                        preservePostal(postal);
                        return null;
                    });
                }
                return window.WelineThemeAddress.applyPostalCandidate(code, Object.assign({
                    country_code: countryCode
                }, rows[0] || {})).then(function (ok) {
                    if (jobId !== postalJobSeq) {
                        return null;
                    }
                    preservePostal(postal);
                    return ok;
                });
            });
        }
        function enqueue() {
            var jobId = ++postalJobSeq;
            setLoading(true);
            postalJobQueue = postalJobQueue.then(function () {
                if (jobId !== postalJobSeq) {
                    return null;
                }
                var field = findPostalFieldForRoot(root);
                var postal = text(field && field.value).trim();
                if (postal.length < 3) {
                    clearPostalCountryPrompt(group);
                    lastEnqueuedPostal = '';
                    finishJob(jobId);
                    return null;
                }
                if (postal === lastEnqueuedPostal) {
                    finishJob(jobId);
                    return null;
                }
                lastEnqueuedPostal = postal;
                group.pendingPostalCode = postal;
                var countryCode = text(metadataValue(group, 'country_code') || (group.fixed && group.fixed.country) || 'CN').toUpperCase() || 'CN';
                return postalCountries(postal).then(function (countries) {
                    if (jobId !== postalJobSeq) {
                        return null;
                    }
                    var field2 = findPostalFieldForRoot(root);
                    var latest = text(field2 && field2.value).trim();
                    if (latest !== postal) {
                        return null;
                    }
                    if (!Array.isArray(countries) || !countries.length) {
                        return applyHit(countryCode, postal, jobId);
                    }
                    if (countries.length === 1) {
                        var onlyRow = countries[0] || {};
                        var only = text(onlyRow.country_code || onlyRow.code || '').toUpperCase() || countryCode;
                        setPostalCountryPin(code, countries, postal);
                        if (onlyRow.embargoed || onlyRow.supported === false) {
                            return clearCascade(countryCode, { keepCountry: false }).then(function () {
                                preservePostal(postal);
                                finishJob(jobId);
                                return promptPostalCountries(code, countries, postal);
                            });
                        }
                        return applyHit(only, postal, jobId);
                    }
                    // 多国命中但当前国已在候选中：直接按当前国回填，禁止 clearCascade 清空市/区。
                    // 否则输入 610500 时前缀 610/6100/61050 会反复清空，竞态下只剩省份。
                    // 例外：当前国命中却禁运/不支持时，必须弹出「邮编匹配」，避免静默回填禁运地、也让浦东等仍显示「不支持」。
                    var preferred = text(countryCode || '').toUpperCase();
                    var preferredRow = null;
                    var preferredHit = preferred && countries.some(function (row) {
                        var cc = text((row && (row.country_code || row.code)) || '').toUpperCase();
                        if (cc === preferred) {
                            preferredRow = row;
                            return true;
                        }
                        return false;
                    });
                    if (preferredHit) {
                        setPostalCountryPin(code, countries, postal);
                        if (preferredRow && (preferredRow.embargoed || preferredRow.supported === false)) {
                            return clearCascade(countryCode, { keepCountry: false }).then(function () {
                                preservePostal(postal);
                                finishJob(jobId);
                                return promptPostalCountries(code, countries, postal);
                            });
                        }
                        return applyHit(preferred, postal, jobId);
                    }
                    return clearCascade(countryCode, { keepCountry: false }).then(function () {
                        preservePostal(postal);
                        finishJob(jobId);
                        return promptPostalCountries(code, countries, postal);
                    });
                }).then(function (result) {
                    finishJob(jobId);
                    return result;
                });
            }).catch(function () {
                finishJob(jobId);
                return null;
            });
        }
        function schedule() {
            window.clearTimeout(postalLookupTimer);
            postalLookupTimer = null;
            var field = findPostalFieldForRoot(root);
            var postal = text(field && field.value).trim();
            if (postal.length < 3) {
                setLoading(false);
                clearPostalCountryPrompt(group);
                lastEnqueuedPostal = '';
                return;
            }
            // Already looked up this exact value: ignore duplicate input (e.g. paste re-fire).
            if (postal === lastEnqueuedPostal) {
                setLoading(false);
                return;
            }
            setLoading(true);
            postalLookupTimer = window.setTimeout(function () {
                postalLookupTimer = null;
                enqueue();
            }, POSTAL_LOOKUP_DEBOUNCE_MS);
        }
        function onPostalEvent(event) {
            var t = event.target;
            if (!(t && t.matches && t.matches(postalFieldSelector))) {
                return;
            }
            // Skip only when we can resolve THIS group's postal and the event is for a sibling field
            // (same form may host shipping + billing). If unresolved, still schedule — do not drop input.
            var mine = findPostalFieldForRoot(root);
            if (mine && t !== mine) {
                return;
            }
            schedule();
        }
        // Prefer form / checkout widget so shell-external data-postal-first receives input.
        // Input-only: do not bind change/blur — paste + leave would double-query.
        var scope = root.closest('form')
            || root.closest('[data-shipping-checkout-address], .w-shipping-checkout-address, [data-address-editor]')
            || root.closest('[data-w-address-shell]')
            || root.parentElement
            || document;
        scope.addEventListener('input', onPostalEvent);
        var postalField = findPostalFieldForRoot(root);
        if (postalField && !scope.contains(postalField)) {
            postalField.addEventListener('input', schedule);
        }
    }

    function boot() {
        document.querySelectorAll('[data-w-address]').forEach(function (node) {
            // data-address-lazy: wait until host arms (e.g. disclosure open) before mount/network.
            if (node.getAttribute('data-address-lazy') === '1' && node.dataset.wAddressLazyArmed !== '1') {
                return;
            }
            mount(node);
        });
    }

    window.WelineThemeAddress = {
        boot: boot,
        groups: groups,
        __regionSources: regionSources,
        __streetSources: streetSources,
        __countryEmbargoCache: countryEmbargoCache,
        __subnationalEmbargoCache: subnationalEmbargoCache,
        applyValues: applyValues,
        postalLookup: postalLookup,
        postalCountries: postalCountries,
        evaluateEmbargo: evaluateEmbargo,
        embargoedCountryCodes: embargoedCountryCodes,
        refreshEmbargoState: refreshEmbargoState,
        setCascadeLoading: setCascadeLoading,
        promptPostalCountries: promptPostalCountries,
        setPostalCountryPin: setPostalCountryPin,
        bindPostalLookup: bindPostalLookup,
        clearPostalCountryPrompt: function (codeOrRoot) {
            clearPostalCountryPrompt(resolveAddressGroup(codeOrRoot));
        },
        applyPostalCandidate: function (codeOrRoot, candidate) {
            candidate = candidate || {};
            var countryCode = text(candidate.country_code || '').toUpperCase();
            var postalCode = text(candidate.postal_code || '');
            return applyValues(codeOrRoot, {
                country_code: countryCode,
                province: '',
                city: '',
                district: '',
                street: ''
            }).then(function (ok) {
                var group = typeof codeOrRoot === 'string' ? groups[codeOrRoot] : null;
                if (!group && codeOrRoot && codeOrRoot.dataset) {
                    group = groups[codeOrRoot.dataset.addressCode];
                }
                if (!group) {
                    return ok;
                }

                // 先套国家字段深度（CN 含 district），否则区县控件可能被默认 profile 隐藏
                return loadCountryProfile(countryCode).then(function (profile) {
                    if (profile) {
                        applyCountryProfile(group, profile);
                    }

                    function ensureControlVisible(level) {
                        var control = group.controls[level];
                        if (control && control.item) {
                            control.item.hidden = false;
                        }
                    }

                    function isOpaqueRegionLabel(name, code) {
                        name = text(name);
                        code = text(code);
                        if (!name) {
                            return true;
                        }
                        if (code && name.toUpperCase() === code.toUpperCase()) {
                            return true;
                        }
                        return /^[A-Z]{2}-C\d+$/i.test(name);
                    }

                    function paintById(level, id, fallbackName, fallbackCode, parentId) {
                        id = text(id);
                        if (!id || !group.controls[level]) {
                            return null;
                        }
                        ensureControlVisible(level);
                        var region = findRegionById(group, id);
                        var displayName = text(fallbackName || '');
                        if (!displayName && isOpaqueRegionLabel('', fallbackCode)) {
                            displayName = '';
                        }
                        if (!region) {
                            region = {
                                region_id: Number(id) || id,
                                parent_region_id: Number(parentId || 0),
                                country_code: countryCode,
                                region_code: text(fallbackCode || ''),
                                region_name: text(displayName || fallbackCode || id),
                                region_type: level,
                                postal_code: postalCode
                            };
                            group.regions = group.regions || [];
                            group.regions.push(region);
                        } else if (displayName && isOpaqueRegionLabel(labelOf(region), region.region_code || fallbackCode)) {
                            region.region_name = displayName;
                        } else if (displayName && !text(region.region_name)) {
                            region.region_name = displayName;
                        }
                        // 禁止把内部代码当展示名
                        if (isOpaqueRegionLabel(labelOf(region), region.region_code || fallbackCode) && displayName) {
                            region.region_name = displayName;
                        }
                        group.state[level] = region;
                        group.controls[level].field.value = labelOf(region);
                        group.controls[level].input.value = labelOf(region);
                        return region;
                    }

                    var chain = Promise.resolve();
                    if (candidate.province_region_id) {
                        chain = chain.then(function () {
                            return loadChildren(null, countryCode, 2000).then(function (rows) {
                                mergeRegions(group, rows);
                                paintById(
                                    'province',
                                    candidate.province_region_id,
                                    candidate.province_name || candidate.province || (text(candidate.attach_level) === 'province' ? candidate.place_name : ''),
                                    candidate.province_code,
                                    0
                                );
                            });
                        });
                    }
                    if (candidate.city_region_id) {
                        chain = chain.then(function () {
                            var parentId = Number(candidate.province_region_id || (group.state.province && group.state.province.region_id) || 0);
                            return loadChildren(parentId, '', 2000).then(function (rows) {
                                mergeRegions(group, rows);
                                var cityName = text(candidate.city_name || candidate.city || '');
                                if (!cityName || isOpaqueRegionLabel(cityName, candidate.city_code)) {
                                    if (text(candidate.attach_level) === 'city' || text(candidate.place_name)) {
                                        cityName = text(candidate.place_name || cityName);
                                    }
                                }
                                paintById(
                                    'city',
                                    candidate.city_region_id,
                                    cityName,
                                    candidate.city_code,
                                    parentId
                                );
                            });
                        });
                    }
                    if (candidate.district_region_id) {
                        chain = chain.then(function () {
                            var parentId = Number(candidate.city_region_id || (group.state.city && group.state.city.region_id) || 0);
                            return loadChildren(parentId, '', 500).then(function (rows) {
                                mergeRegions(group, rows);
                                var districtName = text(candidate.place_name || '');
                                if (text(candidate.attach_level) && text(candidate.attach_level) !== 'district') {
                                    districtName = text(candidate.district_name || candidate.district || districtName);
                                }
                                paintById(
                                    'district',
                                    candidate.district_region_id,
                                    districtName || candidate.district_code,
                                    candidate.district_code,
                                    parentId
                                );
                            });
                        });
                    }

                    return chain.then(function () {
                        if (postalCode) {
                            ['province', 'city', 'district', 'street'].forEach(function (level) {
                                if (group.state[level]) {
                                    group.state[level].postal_code = postalCode;
                                }
                            });
                            var root = null;
                            Object.keys(group.controls).some(function (level) {
                                root = group.controls[level].root;
                                return !!root;
                            });
                            var form = root ? root.closest('form') : null;
                            if (form) {
                                var postalFields = form.querySelectorAll('[data-postal-first], [data-shipping-field][name="postal_code"], [name="postal_code"]');
                                Array.prototype.forEach.call(postalFields, function (postalField) {
                                    var current = text(postalField.value).trim();
                                    var pending = text(group.pendingPostalCode || '').trim();
                                    // Never replace user input with catalog postal (lookup may match on outward prefix).
                                    if (pending) {
                                        postalField.value = pending;
                                        return;
                                    }
                                    if (current) {
                                        return;
                                    }
                                    postalField.value = postalCode;
                                });
                            }
                        }
                        syncMetadata(group);
                        updateGroup(group);
                        syncLevelVisibility(group);
                        // updateGroup/refreshState 后强制回写已 paint 的省/市/区/街，防止仅省残留
                        ['province', 'city', 'district', 'street'].forEach(function (level) {
                            if (group.state[level] && group.controls[level]) {
                                if (group.controls[level].item) {
                                    group.controls[level].item.hidden = false;
                                }
                                group.controls[level].field.value = labelOf(group.state[level]);
                                group.controls[level].input.value = labelOf(group.state[level]);
                            }
                        });
                        // 禁运省市区不得留在已选状态：回填后评估，命中则清空下级并标红
                        return refreshEmbargoState(group).then(function (embargo) {
                            if (!(embargo && embargo.blocked)) {
                                return ok;
                            }
                            ['province', 'city', 'district', 'street'].forEach(function (level) {
                                group.state[level] = null;
                                if (group.controls[level]) {
                                    group.controls[level].field.value = '';
                                    group.controls[level].input.value = '';
                                }
                            });
                            syncMetadata(group);
                            updateGroup(group);
                            return false;
                        }).catch(function () {
                            return ok;
                        });
                    });
                });
            });
        }
    };
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
