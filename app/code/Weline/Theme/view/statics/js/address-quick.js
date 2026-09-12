(function (window, document) {
    'use strict';

    function text(value) {
        return String(value == null ? '' : value).trim();
    }

    function upper(value) {
        return text(value).toUpperCase();
    }

    function readField(root, name) {
        if (!name) return '';
        var el = root.querySelector('[name="' + name + '"]');
        if (!el) {
            el = document.querySelector('[name="' + name + '"]');
        }
        return el ? text(el.value) : '';
    }

    function matchValue(haystack, needle, mode) {
        haystack = upper(haystack);
        needle = upper(needle);
        if (!needle) return true;
        if (!haystack) return false;
        if (mode === 'prefix') return haystack.indexOf(needle) === 0 || haystack.indexOf(needle + '-') === 0;
        if (mode === 'contains') return haystack.indexOf(needle) !== -1;
        return haystack === needle;
    }

    function selectionCodes(root) {
        var countryField = root.getAttribute('data-country-field') || '';
        var provinceField = root.getAttribute('data-province-field') || '';
        var country = upper(readField(root, countryField));
        var province = upper(readField(root, provinceField));
        // Prefer explicit ISO-like tokens from address hidden inputs.
        if (!country) {
            var countryInput = root.querySelector('input[name*="country"], select[name*="country"]');
            if (countryInput) country = upper(countryInput.value).slice(0, 8);
        }
        return { country: country, province: province };
    }

    function applyFilter(root) {
        if (root.getAttribute('data-filter-enabled') !== '1') return;
        var targetSel = text(root.getAttribute('data-filter-target') || '');
        if (!targetSel) return;
        var attr = text(root.getAttribute('data-filter-attr') || 'data-country-code');
        var match = text(root.getAttribute('data-filter-match') || 'exact') || 'exact';
        var emptyMode = text(root.getAttribute('data-filter-empty') || 'all') || 'all';
        var codes = selectionCodes(root);
        var needle = codes.province || codes.country;
        var rows = document.querySelectorAll(targetSel);
        var visible = 0;
        rows.forEach(function (row) {
            var show;
            if (!needle) {
                show = emptyMode !== 'none';
            } else {
                var raw = row.getAttribute(attr) || '';
                show = matchValue(raw, needle, match);
                // Country filter should keep province/warehouse descendants via country attr or code prefix.
                if (!show && codes.country && attr !== 'data-warehouse-code') {
                    var wh = row.getAttribute('data-warehouse-code') || '';
                    show = matchValue(wh, codes.country, 'prefix');
                }
            }
            row.hidden = !show;
            if (show) visible += 1;
        });
        var status = root.querySelector('[data-aq-status]');
        if (status) {
            if (!needle) {
                status.hidden = true;
                status.textContent = '';
            } else {
                status.hidden = false;
                status.textContent = (window.WelineThemeAddressQuick && window.WelineThemeAddressQuick.i18nFilter)
                    ? window.WelineThemeAddressQuick.i18nFilter(needle, visible)
                    : ('已过滤：' + needle + '（' + visible + '）');
            }
        }
        root.dispatchEvent(new CustomEvent('weline:address-quick:change', {
            bubbles: true,
            detail: { country: codes.country, province: codes.province, needle: needle, visible: visible }
        }));
    }

    function syncAddFields(root) {
        if (root.getAttribute('data-add-enabled') !== '1') return;
        var codes = selectionCodes(root);
        var countryHidden = root.querySelector('[data-aq-country-code]');
        var regionHidden = root.querySelector('[data-aq-region-code]');
        var segment = root.querySelector('[data-aq-segment]');
        var nameInput = root.querySelector('[data-aq-name]');
        if (countryHidden) countryHidden.value = codes.country;
        if (regionHidden) regionHidden.value = codes.province;
        if (segment && segment.readOnly) {
            segment.value = codes.province || codes.country;
        } else if (segment && !segment.value && (codes.province || codes.country)) {
            segment.value = codes.province || codes.country;
        }
        if (nameInput && !nameInput.value) {
            // Keep name empty for user input; only seed segment/code.
        }
    }

    function bindRoot(root) {
        if (!root || root.getAttribute('data-aq-bound') === '1') return;
        root.setAttribute('data-aq-bound', '1');
        var onChange = function () {
            syncAddFields(root);
            applyFilter(root);
        };
        root.addEventListener('change', onChange, true);
        root.addEventListener('input', onChange, true);
        // Address multi events (if present inside).
        root.addEventListener('weline:address:multi-change', onChange);
        onChange();
    }

    function boot(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        root.querySelectorAll('[data-w-address-quick="1"]').forEach(bindRoot);
    }

    window.WelineThemeAddressQuick = {
        boot: boot,
        applyFilter: function (el) { if (el) applyFilter(el); },
        i18nFilter: null
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(document); });
    } else {
        boot(document);
    }
})(window, document);
