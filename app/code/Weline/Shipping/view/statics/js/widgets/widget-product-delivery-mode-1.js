window.WelineWidgetAssets.register('shipping-product-delivery-mode-1', function (widgetScript) {
(function () {
    'use strict';

    const STORAGE_KEY = 'weline_delivery_location';
    const COOKIE_KEY = 'weline_delivery_location';
    const EVENT_NAME = 'weline:delivery-country-changed';

    const labelsRaw = widgetScript && widgetScript.getAttribute('data-v0');
    let labels = {};
    try {
        labels = labelsRaw ? JSON.parse(labelsRaw) : {};
    } catch (e) {
        labels = {};
    }

    const roots = Array.prototype.slice.call(
        document.querySelectorAll('[data-shipping-delivery-mode]')
    );
    if (!roots.length) {
        return;
    }

    function normalizeCountry(raw) {
        const code = String(raw || '').replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 2);
        return code.length === 2 ? code : '';
    }

    function readCookie(name) {
        const parts = String(document.cookie || '').split(';');
        for (let i = 0; i < parts.length; i++) {
            const part = parts[i].trim();
            if (part.indexOf(name + '=') === 0) {
                try {
                    return decodeURIComponent(part.slice(name.length + 1));
                } catch (e) {
                    return part.slice(name.length + 1);
                }
            }
        }
        return '';
    }

    function parseLocationPayload(raw) {
        if (!raw) {
            return '';
        }
        try {
            const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
            if (!data || typeof data !== 'object') {
                return '';
            }
            return normalizeCountry(data.country_code || data.countryCode || '');
        } catch (e) {
            return '';
        }
    }

    function readDestFromHeader() {
        const widget = document.querySelector('[data-checkout-delivery-widget]');
        if (!widget) {
            return '';
        }
        const fromAttr = normalizeCountry(
            widget.getAttribute('data-country-code')
            || widget.getAttribute('data-current-country')
        );
        if (fromAttr) {
            return fromAttr;
        }
        const input = widget.querySelector('[name="country_code"], [data-country-code-input]');
        if (input && input.value) {
            return normalizeCountry(input.value);
        }
        return '';
    }

    let forcedDest = '';

    function resolveDestCountry() {
        if (forcedDest) {
            return forcedDest;
        }
        try {
            const fromStorage = parseLocationPayload(localStorage.getItem(STORAGE_KEY));
            if (fromStorage) {
                return fromStorage;
            }
        } catch (e) {
            // ignore
        }
        const fromCookie = parseLocationPayload(readCookie(COOKIE_KEY));
        if (fromCookie) {
            return fromCookie;
        }
        return readDestFromHeader();
    }

    function resolveMode(origin, dest) {
        if (!origin || !dest) {
            return 'standard';
        }
        return origin === dest ? 'local_fast' : 'cross_border';
    }

    function applyRoot(root) {
        const origin = normalizeCountry(
            root.getAttribute('data-origin-country')
            || (widgetScript && widgetScript.getAttribute('data-v1'))
            || ''
        );
        const dest = resolveDestCountry();
        const mode = resolveMode(origin, dest);
        const pack = labels[mode] || labels.standard || {};
        const badge = root.querySelector('[data-shipping-delivery-badge]');
        const helper = root.querySelector('[data-shipping-delivery-helper]');
        if (badge) {
            badge.textContent = String(pack.badge || '');
        }
        if (helper) {
            helper.textContent = String(pack.helper || '');
        }
        root.setAttribute('data-delivery-mode', mode);
        root.setAttribute('data-dest-country', dest);
        if (origin) {
            root.setAttribute('data-origin-country', origin);
        }
    }

    function refreshAll() {
        roots.forEach(applyRoot);
    }

    refreshAll();

    window.addEventListener(EVENT_NAME, function (ev) {
        const code = normalizeCountry(
            ev && ev.detail
                ? (ev.detail.country_code || ev.detail.countryCode || '')
                : ''
        );
        if (code) {
            forcedDest = code;
        }
        refreshAll();
    });
    window.addEventListener('storage', function (ev) {
        if (!ev || ev.key === STORAGE_KEY || ev.key === null) {
            refreshAll();
        }
    });

    const header = document.querySelector('[data-checkout-delivery-widget]');
    if (header && typeof MutationObserver === 'function') {
        const mo = new MutationObserver(refreshAll);
        mo.observe(header, { attributes: true, childList: true, subtree: true });
    }

    document.addEventListener('change', function (ev) {
        const t = ev && ev.target;
        if (!t || !t.closest) {
            return;
        }
        if (t.closest('[data-checkout-delivery-widget]')) {
            refreshAll();
        }
    });

    document.addEventListener('click', function (ev) {
        const t = ev && ev.target;
        if (!t || !t.closest) {
            return;
        }
        if (t.closest('[data-checkout-delivery-widget]')) {
            window.setTimeout(refreshAll, 450);
        }
    }, true);
})();
});
