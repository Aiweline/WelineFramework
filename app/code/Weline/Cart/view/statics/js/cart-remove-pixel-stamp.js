/**
 * Cart remove_from_cart Visitor pixel markers (event_dictionary).
 * Standalone stamp so WLS in-worker /static cache on cart.js cannot block markers.
 */
(function (global) {
    'use strict';

    var PIXEL_CLASS = 'weline-pixel::remove_from_cart';
    var PIXEL_EVENT = 'remove_from_cart';
    var SELECTOR = '[data-cart-action="remove"], [data-remove-item]';
    var observer = null;

    function stamp(root) {
        if (!global.document || !global.document.querySelectorAll) {
            return 0;
        }
        var scope = root && root.querySelectorAll ? root : global.document;
        var nodes = scope.querySelectorAll(SELECTOR);
        var n = 0;
        for (var i = 0; i < nodes.length; i++) {
            var btn = nodes[i];
            if (!btn) {
                continue;
            }
            var cls = btn.className || '';
            if (cls.indexOf(PIXEL_CLASS) === -1) {
                btn.className = (cls ? cls + ' ' : '') + PIXEL_CLASS;
            }
            if (btn.getAttribute('data-pixel-event') !== PIXEL_EVENT) {
                btn.setAttribute('data-pixel-event', PIXEL_EVENT);
            }
            var line = btn.closest
                ? (btn.closest('[data-cart-line], [data-cart-item], .weline-cart-shell__line, .mini-cart-drawer__item') || null)
                : null;
            if (line) {
                if (!btn.getAttribute('data-product-id') && line.getAttribute('data-product-id')) {
                    btn.setAttribute('data-product-id', line.getAttribute('data-product-id'));
                }
                if (!btn.getAttribute('data-price') && (line.getAttribute('data-price') || line.getAttribute('data-pixel-value'))) {
                    btn.setAttribute('data-price', line.getAttribute('data-price') || line.getAttribute('data-pixel-value'));
                }
                if (!btn.getAttribute('data-currency') && (line.getAttribute('data-currency') || line.getAttribute('data-pixel-currency'))) {
                    var cur = line.getAttribute('data-currency') || line.getAttribute('data-pixel-currency');
                    btn.setAttribute('data-currency', cur);
                    btn.setAttribute('data-pixel-currency', cur);
                }
            }
            n += 1;
        }
        return n;
    }

    function observe() {
        stamp(global.document);
        if (observer || !global.MutationObserver || !global.document.body) {
            return;
        }
        observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
                    stamp(mutations[i].target || global.document);
                    return;
                }
            }
        });
        observer.observe(global.document.body, { childList: true, subtree: true });
    }

    global.WelineCartRemovePixel = {
        stamp: stamp,
        observe: observe,
        PIXEL_CLASS: PIXEL_CLASS,
        PIXEL_EVENT: PIXEL_EVENT,
    };

    if (global.document.readyState === 'loading') {
        global.document.addEventListener('DOMContentLoaded', observe);
    } else {
        observe();
    }
})(typeof window !== 'undefined' ? window : this);
