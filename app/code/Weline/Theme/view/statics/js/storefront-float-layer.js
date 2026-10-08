/**
 * Storefront float-layer adopt fallback.
 * Primary path is SSR into footer w:slots storefront-float-start/end.
 * This only moves stragglers that still render as body siblings (legacy / late inject).
 */
(function () {
  'use strict';

  var LAYER_SEL = '#w-storefront-float-layer';
  var START_SEL = '[data-float-slot="start"]';
  var END_SEL = '[data-float-slot="end"]';
  var MUSIC_SEL = '.w-store-music-host[data-store-music-host], .w-store-music[data-store-music]';
  var CS_SEL = '#customer-service-widget, .customer-service-widget';

  function layer() {
    return document.querySelector(LAYER_SEL);
  }

  function slot(which) {
    var root = layer();
    if (!root) {
      return null;
    }
    return root.querySelector(which === 'end' ? END_SEL : START_SEL);
  }

  function alreadyInSlot(el, slotEl) {
    return !!(el && slotEl && slotEl.contains(el));
  }

  function adoptOne(el, slotEl) {
    if (!el || !slotEl || alreadyInSlot(el, slotEl)) {
      return;
    }
    if (el.closest && el.closest(LAYER_SEL)) {
      return;
    }
    slotEl.appendChild(el);
  }

  function adoptMusic() {
    var start = slot('start');
    if (!start) {
      return;
    }
    var hosts = document.querySelectorAll('.w-store-music-host[data-store-music-host]');
    if (hosts.length) {
      for (var i = 0; i < hosts.length; i++) {
        adoptOne(hosts[i], start);
      }
      return;
    }
    var music = document.querySelectorAll('.w-store-music[data-store-music]');
    for (var j = 0; j < music.length; j++) {
      adoptOne(music[j], start);
    }
  }

  function adoptCs() {
    var end = slot('end');
    if (!end) {
      return;
    }
    var nodes = document.querySelectorAll(CS_SEL);
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (el.id === 'customer-service-widget' || el.classList.contains('customer-service-widget')) {
        adoptOne(el, end);
      }
    }
  }

  function sync() {
    if (!layer()) {
      return;
    }
    adoptMusic();
    adoptCs();
  }

  function boot() {
    sync();
    if (typeof MutationObserver === 'function') {
      var obs = new MutationObserver(function () {
        sync();
      });
      obs.observe(document.documentElement, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
