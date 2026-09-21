/**
 * PayPal JS SDK wallet buttons (Google Pay / Apple Pay funding).
 * Payment module only — Checkout must not load paypal.com/sdk.
 */
(function (global) {
  'use strict';

  function readSrc(root) {
    if (!root) return '';
    return String(root.getAttribute('data-paypal-sdk-src') || '').trim();
  }

  function loadSdk(src) {
    return new Promise(function (resolve, reject) {
      if (!src) {
        reject(new Error('missing paypal sdk src'));
        return;
      }
      if (global.paypal && typeof global.paypal.Buttons === 'function') {
        resolve(global.paypal);
        return;
      }
      var existing = document.querySelector('script[data-weline-paypal-sdk="1"]');
      if (existing) {
        existing.addEventListener('load', function () { resolve(global.paypal); });
        existing.addEventListener('error', function () { reject(new Error('paypal sdk load failed')); });
        return;
      }
      var script = document.createElement('script');
      script.src = src;
      script.async = true;
      script.setAttribute('data-weline-paypal-sdk', '1');
      script.onload = function () { resolve(global.paypal); };
      script.onerror = function () { reject(new Error('paypal sdk load failed')); };
      document.head.appendChild(script);
    });
  }

  function mount(root) {
    if (!root || root.getAttribute('data-paypal-wallet-mounted') === '1') {
      return;
    }
    var src = readSrc(root);
    if (!src) {
      return;
    }
    root.setAttribute('data-paypal-wallet-mounted', '1');
    loadSdk(src).then(function (paypal) {
      if (!paypal || typeof paypal.Buttons !== 'function') {
        return;
      }
      var container = root.querySelector('#paypal-button-container') || root;
      // Phase 1: render Buttons; createOrder falls back to existing redirect CTA
      // (full onApprove/capture is Phase 2). Clicking still opens PayPal funding UX.
      try {
        paypal.Buttons({
          style: { layout: 'vertical', shape: 'rect' },
          createOrder: function () {
            return Promise.reject(new Error('use_redirect_cta'));
          },
          onError: function () {
            // Intentional: wallet UI may appear; payment completion stays on redirect CTA.
          }
        }).render(container);
      } catch (e) {
        // ignore render errors in unsupported browsers
      }
    }).catch(function () {
      root.removeAttribute('data-paypal-wallet-mounted');
    });
  }

  function boot(ctx) {
    var roots = [];
    if (ctx && ctx.el) {
      roots.push(ctx.el);
    }
    document.querySelectorAll('[data-weline-load*="paypalWalletButtons"], [data-testid="paypal-wallet-buttons"]').forEach(function (el) {
      roots.push(el);
    });
    roots.forEach(mount);
  }

  global.WelineModules = global.WelineModules || {};
  global.WelineModules.paypalWalletButtons = { boot: boot, mount: mount };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { boot({}); });
  } else {
    boot({});
  }
})(typeof window !== 'undefined' ? window : this);
