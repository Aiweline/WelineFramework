(function (global) {
    'use strict';

    var HOST_SELECTOR = '[data-points-discount-host]';
    var INPUT_NAME = 'points_discount_apply_minor';
    var state = {
        quote: null,
        apply: null,
        busy: false
    };

    function t(host, key, fallback) {
        return String(host.getAttribute('data-label-' + key) || fallback || '');
    }

    function onRelevantPage() {
        var path = String(global.location && global.location.pathname || '').toLowerCase();
        if (/\/admin(\/|$)/.test(path)) {
            return false;
        }
        if (/cart|checkout|mini-cart|minicart/.test(path)) {
            return true;
        }
        return !!document.querySelector('[data-mini-cart], .mini-cart, .mini-cart-drawer, [data-weline-mini-cart]');
    }

    function precisionFor(currency) {
        var code = String(currency || '').toUpperCase();
        if (['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'].indexOf(code) >= 0) {
            return 0;
        }
        if (['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'].indexOf(code) >= 0) {
            return 3;
        }
        return 2;
    }

    function readCurrency() {
        var site = global.site || {};
        return String(site.currency_code || site.currency || (document.documentElement && document.documentElement.dataset.currency) || 'CNY').toUpperCase();
    }

    function readWebsiteId() {
        var site = global.site || {};
        var value = parseInt(site.website_id || site.websiteId || (document.documentElement && document.documentElement.dataset.websiteId) || '0', 10);
        return isNaN(value) || value < 0 ? 0 : value;
    }

    function parseMajor(text) {
        var cleaned = String(text || '').replace(/[^0-9.,-]/g, '').replace(/,/g, '');
        var value = parseFloat(cleaned);
        return isNaN(value) || value < 0 ? 0 : value;
    }

    function readAmountMinor() {
        var attrs = [
            'data-money-summary-payable-minor',
            'data-points-amount-minor',
            'data-cart-total-minor',
            'data-checkout-total-minor',
            'data-order-total-minor',
            'data-grand-total-minor'
        ];
        var i;
        for (i = 0; i < attrs.length; i += 1) {
            var el = document.querySelector('[' + attrs[i] + ']');
            if (el) {
                var minor = parseInt(el.getAttribute(attrs[i]) || '0', 10);
                if (!isNaN(minor) && minor > 0) {
                    return minor;
                }
            }
        }
        var textSelectors = [
            '[data-money-summary-payable]',
            '[data-cart-total]',
            '[data-checkout-total]',
            '[data-grand-total]',
            '[data-order-total]',
            '.cart-total',
            '.checkout-total',
            '.order-total',
            '[data-total]'
        ];
        for (i = 0; i < textSelectors.length; i += 1) {
            var node = document.querySelector(textSelectors[i]);
            if (node) {
                var major = parseMajor(node.getAttribute('data-total') || node.textContent || '');
                if (major > 0) {
                    return Math.round(major * Math.pow(10, precisionFor(readCurrency())));
                }
            }
        }
        return 0;
    }

    function findMount(host) {
        var selectors = [
            '[data-checkout-summary]',
            '.weline-checkout__extras',
            '.checkout-summary',
            '[data-cart-summary-extras]',
            '.cart-summary',
            '[data-w-mini-cart] .mini-cart-drawer__extras',
            '[data-mini-cart-drawer] .mini-cart-drawer__extras',
            '.mini-cart-drawer__extras',
            '.mini-cart-drawer',
            '[data-mini-cart]',
            '.mini-cart',
            '[data-weline-mini-cart]'
        ];
        for (var i = 0; i < selectors.length; i += 1) {
            var node = document.querySelector(selectors[i]);
            if (node) {
                return node;
            }
        }
        return host.parentNode || document.body;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function render(host, quote) {
        if (!quote || !quote.enabled) {
            host.hidden = true;
            return;
        }
        var precision = precisionFor(quote.checkout_currency || readCurrency());
        var scale = Math.pow(10, precision);
        host.hidden = false;
        host.textContent = '';

        var panel = el('div', 'points-discount-panel');
        panel.style.cssText = 'margin:12px 0;padding:12px;border:1px solid currentColor;border-radius:8px;display:grid;gap:8px';

        panel.appendChild(el('strong', '', t(host, 'title', 'Use points')));
        var maxMajor = (Number(quote.max_apply_checkout_minor || 0) / scale).toFixed(precision);
        panel.appendChild(el('div', 'points-discount-hint', t(host, 'available', 'Available points') + ': ' + String(quote.points_available || 0)));
        panel.appendChild(el('div', 'points-discount-hint', t(host, 'max', 'Maximum') + ': ' + maxMajor + ' ' + String(quote.checkout_currency || '')));

        var label = el('label', '', t(host, 'amount', 'Redemption amount'));
        var input = document.createElement('input');
        input.type = 'number';
        input.min = '0';
        input.step = String(1 / scale);
        input.max = maxMajor;
        input.className = 'points-discount-input';
        input.style.cssText = 'display:block;width:100%;margin-top:4px';
        if (state.apply && state.apply.apply_checkout_minor) {
            input.value = (Number(state.apply.apply_checkout_minor) / scale).toFixed(precision);
        }
        label.appendChild(input);
        panel.appendChild(label);

        var actions = el('div', 'points-discount-actions');
        actions.style.cssText = 'display:flex;gap:8px';

        var apply = el('button', 'points-discount-apply', t(host, 'apply', 'Use points'));
        apply.type = 'button';
        apply.addEventListener('click', function () {
            var minor = Math.round(parseFloat(input.value || '0') * scale);
            applyPoints(host, minor);
        });

        var clear = el('button', 'points-discount-clear', t(host, 'clear', 'Cancel'));
        clear.type = 'button';
        clear.addEventListener('click', function () {
            clearPoints(host);
        });

        actions.appendChild(apply);
        actions.appendChild(clear);
        panel.appendChild(actions);

        var status = el('div', 'points-discount-status', state.apply ? t(host, 'applied', 'Applied') : '');
        panel.appendChild(status);

        host.appendChild(panel);
    }

    function api() {
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            return Promise.resolve(null);
        }
        try {
            return Promise.resolve(global.Weline.Api.resource('points')).catch(function () {
                return null;
            });
        } catch (e) {
            return Promise.resolve(null);
        }
    }

    function quote(host) {
        var amount = readAmountMinor();
        if (amount <= 0) {
            host.hidden = true;
            return;
        }
        return api().then(function (resource) {
            if (!resource || typeof resource['discount.quote'] !== 'function') {
                host.hidden = true;
                return;
            }
            return resource['discount.quote']({
                website_id: readWebsiteId(),
                currency: readCurrency(),
                amount_minor: amount
            }, { silent: true }).then(function (res) {
                var quoteData = res && (res.points_discount || (res.data && res.data.points_discount));
                state.quote = quoteData || null;
                render(host, state.quote);
            });
        }).catch(function () {
            host.hidden = true;
        });
    }

    function applyPoints(host, minor) {
        if (state.busy) {
            return;
        }
        if (minor <= 0) {
            clearPoints(host);
            return;
        }
        state.busy = true;
        api().then(function (resource) {
            if (!resource || typeof resource['discount.apply'] !== 'function') {
                return;
            }
            return resource['discount.apply']({
                website_id: readWebsiteId(),
                currency: readCurrency(),
                amount_minor: readAmountMinor(),
                apply_minor: minor
            }, { silent: true }).then(function (res) {
                var payload = res && (res.applied || (res.data && res.data.applied));
                if (payload && payload.points > 0) {
                    state.apply = payload;
                    writeHiddenInput(payload.apply_checkout_minor);
                    global.__welinePointsDiscountApplyMinor = payload.apply_checkout_minor;
                    document.dispatchEvent(new CustomEvent('points:discount:changed', { detail: payload }));
                }
                render(host, state.quote);
            });
        }).catch(function () {
        }).then(function () {
            state.busy = false;
        });
    }

    function clearPoints(host) {
        if (state.busy) {
            return;
        }
        state.busy = true;
        api().then(function (resource) {
            if (!resource || typeof resource['discount.clear'] !== 'function') {
                return;
            }
            return resource['discount.clear']({}, { silent: true }).then(function () {
                state.apply = null;
                writeHiddenInput(0);
                global.__welinePointsDiscountApplyMinor = 0;
                document.dispatchEvent(new CustomEvent('points:discount:changed', { detail: null }));
                render(host, state.quote);
            });
        }).catch(function () {
        }).then(function () {
            state.busy = false;
        });
    }

    function writeHiddenInput(minor) {
        var form = document.querySelector('[data-checkout-form], form.checkout, form');
        var target = form || document.body;
        var input = document.querySelector('input[name="' + INPUT_NAME + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = INPUT_NAME;
            target.appendChild(input);
        }
        input.value = String(minor > 0 ? minor : 0);
    }

    function boot() {
        if (!onRelevantPage()) {
            return;
        }
        var host = document.querySelector(HOST_SELECTOR);
        if (!host) {
            return;
        }
        var mount = findMount(host);
        if (mount !== host.parentNode) {
            mount.appendChild(host);
        }
        quote(host);
    }

    global.WelinePointsCheckout = {
        boot: boot,
        quote: quote,
        applyMinor: function () {
            return state.apply ? state.apply.apply_checkout_minor : 0;
        },
        state: state
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
