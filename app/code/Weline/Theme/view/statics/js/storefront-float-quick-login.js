/**
 * Weline Theme — storefront eight-direction float: async quick-login surface.
 *
 * Runs after window.load + requestIdleCallback (never blocks the main JS).
 * Mounts Customer's declarative social-quick surface into the top-end float slot;
 * the provider itself loads account modules and decides guest/signed-in visibility.
 *
 * No inline widget markup: the slot stays an empty container until the async mount.
 * Marker: storefront-float-quick-login-async-v1
 */
(function (window) {
  'use strict';

  var LAYER_ID = 'w-storefront-float-layer';
  var TOP_END_SLOT = 'storefront-float-top-end';
  var SURFACE = 'customer/social-quick';
  var MODULES = 'account,customerSocialQuick';
  var HOST_CLASS = 'w-float-quick-login';

  var scheduled = false;

  function mountQuickLogin() {
    if (!document.body) {
      return;
    }
    // SSR login page already renders the full login panel; do not duplicate it.
    if (document.querySelector('[data-w-component="account-social-login"]')) {
      return;
    }
    var layer = document.getElementById(LAYER_ID);
    if (!layer) {
      return;
    }
    var slot = layer.querySelector('[data-slot-id="' + TOP_END_SLOT + '"]');
    if (!slot || slot.querySelector('[data-weline-mount="' + SURFACE + '"]')) {
      return;
    }
    var host = document.createElement('div');
    host.className = HOST_CLASS;
    host.setAttribute('data-weline-mount', SURFACE);
    host.setAttribute('data-weline-mount-load', MODULES);
    host.setAttribute('data-weline-mount-variant', 'inline');
    host.setAttribute('data-weline-mount-state', 'pending');
    slot.appendChild(host);

    var mount = window.Weline && window.Weline.mount;
    if (!mount || typeof mount.scan !== 'function') {
      return;
    }
    try {
      mount.scan();
    } catch (_error) {
      /* Enhancement only — never break the page. */
    }
  }

  function idle(run) {
    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(run, { timeout: 2000 });
      return;
    }
    window.setTimeout(run, 200);
  }

  function schedule() {
    if (scheduled) {
      return;
    }
    scheduled = true;
    var run = function () {
      scheduled = false;
      try {
        mountQuickLogin();
      } catch (_error) {
        /* Enhancement only. */
      }
    };
    if (document.readyState === 'complete') {
      idle(run);
      return;
    }
    window.addEventListener('load', function () {
      idle(run);
    }, { once: true });
  }

  schedule();
})(typeof window !== 'undefined' ? window : this);
