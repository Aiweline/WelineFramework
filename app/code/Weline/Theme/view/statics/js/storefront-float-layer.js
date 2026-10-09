/**
 * Storefront float-layer:
 * 1) adopt fallback — move legacy body-sibling music/CS into start/end slots
 * 2) edge dock — collapse each occupied slot to the viewport edge with localStorage
 *
 * Owning module: Weline_Theme (mechanism). Widgets stay content-only.
 * Marker: storefront-float-edge-dock-v1
 * Marker: storefront-float-edge-transform-clear-v1
 * Marker: storefront-float-load-on-layer-v1
 */
(function (global) {
  'use strict';

  var LAYER_SEL = '#w-storefront-float-layer';
  var START_SEL = '[data-float-slot="start"]';
  var END_SEL = '[data-float-slot="end"]';
  var CS_SEL = '#customer-service-widget, .customer-service-widget';
  var STORAGE_PREFIX = 'weline.storefrontFloat.edgeCollapsed.';
  var boundSlots = typeof WeakSet === 'function' ? new WeakSet() : null;
  var syncScheduled = false;
  var syncing = false;

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

  function edgeBody(slotEl) {
    return slotEl ? slotEl.querySelector('[data-float-edge-body]') : null;
  }

  function adoptOne(el, slotEl) {
    if (!el || !slotEl || alreadyInSlot(el, slotEl)) {
      return;
    }
    if (el.closest && el.closest(LAYER_SEL)) {
      return;
    }
    var body = edgeBody(slotEl);
    (body || slotEl).appendChild(el);
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

  function storageKey(side) {
    return STORAGE_PREFIX + (side === 'end' ? 'end' : 'start');
  }

  function readStoredCollapsed(side) {
    try {
      return !!(global.localStorage && global.localStorage.getItem(storageKey(side)) === '1');
    } catch (e) {
      return false;
    }
  }

  function writeStoredCollapsed(side, collapsed) {
    try {
      if (!global.localStorage) {
        return;
      }
      if (collapsed) {
        global.localStorage.setItem(storageKey(side), '1');
      } else {
        global.localStorage.removeItem(storageKey(side));
      }
    } catch (e) {
      /* ignore quota / private mode */
    }
  }

  function sideOf(slotEl) {
    var raw = (slotEl.getAttribute('data-float-slot') || 'start').toLowerCase();
    return raw === 'end' ? 'end' : 'start';
  }

  function applyShift(slotEl, collapsed) {
    var side = sideOf(slotEl);
    // Expanded: clear inline transform. A leftover identity translate still
    // creates a containing block so mobile CS position:fixed panels size to the
    // FAB slot and appear to vanish. Collapsed: class CSS owns ±100% shift.
    // Marker: storefront-float-edge-transform-clear-v1
    if (!collapsed) {
      slotEl.style.transform = '';
      slotEl.style.removeProperty('transform');
      return;
    }
    slotEl.style.transform = side === 'end' ? 'translateX(100%)' : 'translateX(-100%)';
  }

  function slotHasDockableContent(slotEl) {
    var body = edgeBody(slotEl) || slotEl;
    if (!body) {
      return false;
    }
    return !!body.querySelector(
      '.w-store-music-host[data-store-music-host], .w-store-music[data-store-music], .customer-service-widget, [data-widget-code]:not([data-store-music-skipped])'
    );
  }

  function ensureChrome(slotEl) {
    var side = sideOf(slotEl);
    var body = edgeBody(slotEl);
    if (!body) {
      body = document.createElement('div');
      body.className = 'w-storefront-float-edge__body';
      body.setAttribute('data-float-edge-body', '');
      var move = [];
      for (var i = 0; i < slotEl.childNodes.length; i++) {
        move.push(slotEl.childNodes[i]);
      }
      for (var m = 0; m < move.length; m++) {
        var node = move[m];
        if (node.nodeType === 1 && node.hasAttribute && node.hasAttribute('data-float-edge-chrome')) {
          continue;
        }
        body.appendChild(node);
      }
      slotEl.appendChild(body);
    }

    var chrome = slotEl.querySelector('[data-float-edge-chrome]');
    if (!chrome) {
      chrome = document.createElement('div');
      chrome.className = 'w-storefront-float-edge__chrome';
      chrome.setAttribute('data-float-edge-chrome', '');
      chrome.setAttribute('data-float-edge-side', side);

      var dismissLabel = slotEl.getAttribute('data-edge-dismiss-label') || '收起悬浮';
      var recallLabel = slotEl.getAttribute('data-edge-recall-label') || '展开悬浮';

      var dismiss = document.createElement('button');
      dismiss.type = 'button';
      dismiss.className = 'w-storefront-float-edge__dismiss';
      dismiss.setAttribute('data-float-edge-dismiss', '');
      dismiss.setAttribute('data-testid', 'storefront-float-edge-dismiss-' + side);
      dismiss.setAttribute('aria-label', dismissLabel);
      dismiss.title = dismissLabel;
      dismiss.innerHTML = '<span aria-hidden="true">×</span>';

      var recall = document.createElement('button');
      recall.type = 'button';
      recall.className = 'w-storefront-float-edge__recall';
      recall.setAttribute('data-float-edge-recall', '');
      recall.setAttribute('data-testid', 'storefront-float-edge-recall-' + side);
      recall.setAttribute('aria-label', recallLabel);
      recall.title = recallLabel;
      recall.innerHTML =
        '<span class="w-storefront-float-edge__recall-icon" aria-hidden="true">' +
        (side === 'end' ? '‹' : '›') +
        '</span>';

      chrome.appendChild(dismiss);
      chrome.appendChild(recall);
      slotEl.insertBefore(chrome, body);
    }

    return {
      body: body,
      chrome: chrome,
      dismiss: chrome.querySelector('[data-float-edge-dismiss]'),
      recall: chrome.querySelector('[data-float-edge-recall]'),
    };
  }

  function setCollapsed(slotEl, collapsed, persist) {
    var side = sideOf(slotEl);
    var parts = ensureChrome(slotEl);
    var dismissLabel = slotEl.getAttribute('data-edge-dismiss-label') || '收起悬浮';
    var recallLabel = slotEl.getAttribute('data-edge-recall-label') || '展开悬浮';

    slotEl.classList.toggle('is-edge-collapsed', collapsed);
    slotEl.setAttribute('data-edge-collapsed', collapsed ? '1' : '0');
    applyShift(slotEl, collapsed);

    if (parts.body) {
      parts.body.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
      if ('inert' in parts.body) {
        parts.body.inert = collapsed;
      }
    }

    if (parts.dismiss) {
      parts.dismiss.hidden = collapsed;
      parts.dismiss.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
      parts.dismiss.tabIndex = collapsed ? -1 : 0;
      parts.dismiss.setAttribute('aria-label', dismissLabel);
    }

    if (parts.recall) {
      parts.recall.hidden = !collapsed;
      parts.recall.setAttribute('aria-hidden', collapsed ? 'false' : 'true');
      parts.recall.tabIndex = collapsed ? 0 : -1;
      parts.recall.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      parts.recall.setAttribute('aria-label', recallLabel);
      var icon = parts.recall.querySelector('.w-storefront-float-edge__recall-icon');
      if (icon) {
        icon.textContent = side === 'end' ? '‹' : '›';
      }
    }

    if (persist !== false) {
      writeStoredCollapsed(side, collapsed);
    }
  }

  function clearDockVisual(slotEl) {
    slotEl.classList.remove('is-edge-collapsed');
    slotEl.removeAttribute('data-edge-collapsed');
    slotEl.style.transform = '';
    var chrome = slotEl.querySelector('[data-float-edge-chrome]');
    if (chrome) {
      chrome.hidden = true;
    }
  }

  function bindDock(slotEl) {
    if (!slotEl || (boundSlots && boundSlots.has(slotEl))) {
      return;
    }
    var parts = ensureChrome(slotEl);
    if (boundSlots) {
      boundSlots.add(slotEl);
    }
    slotEl.setAttribute('data-float-edge-dock', '1');

    if (parts.dismiss) {
      parts.dismiss.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        setCollapsed(slotEl, true, true);
      });
    }
    if (parts.recall) {
      parts.recall.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        setCollapsed(slotEl, false, true);
      });
    }
  }

  function endHasOpenCustomerService(slotEl) {
    return !!(
      slotEl &&
      slotEl.querySelector(
        '#customer-service-widget.is-open, .customer-service-widget.is-open'
      )
    );
  }

  function syncDock(slotEl) {
    if (!slotEl) {
      return;
    }
    if (!slotHasDockableContent(slotEl)) {
      clearDockVisual(slotEl);
      return;
    }
    bindDock(slotEl);
    var chrome = slotEl.querySelector('[data-float-edge-chrome]');
    if (chrome) {
      chrome.hidden = false;
    }
    var side = sideOf(slotEl);
    var want = readStoredCollapsed(side);
    // Open CS uses mobile position:fixed panel — never keep end slot collapsed
    // (transform CB + visibility:hidden body would swallow the panel).
    if (side === 'end' && endHasOpenCustomerService(slotEl)) {
      want = false;
    }
    var is = slotEl.classList.contains('is-edge-collapsed');
    if (want !== is || !slotEl.hasAttribute('data-edge-collapsed')) {
      setCollapsed(slotEl, want, false);
    } else if (!want) {
      // Re-assert clear transform even when class already expanded (legacy
      // identity-translate inline from older builds).
      applyShift(slotEl, false);
    }
  }

  function syncDocks() {
    syncDock(slot('start'));
    syncDock(slot('end'));
  }

  function sync() {
    if (syncing || !layer()) {
      return;
    }
    syncing = true;
    try {
      adoptMusic();
      adoptCs();
      syncDocks();
    } finally {
      syncing = false;
    }
  }

  function scheduleSync() {
    if (syncScheduled || syncing) {
      return;
    }
    syncScheduled = true;
    var run = function () {
      syncScheduled = false;
      sync();
    };
    if (typeof global.requestAnimationFrame === 'function') {
      global.requestAnimationFrame(run);
    } else {
      setTimeout(run, 16);
    }
  }

  function bindCsOpenClearsEndTransform() {
    document.addEventListener(
      'click',
      function (event) {
        var t = event.target;
        if (!t || !t.closest) {
          return;
        }
        if (!t.closest('[data-cs-toggle-chat], #cs-chat-button')) {
          return;
        }
        var end = slot('end');
        if (!end) {
          return;
        }
        // Expand + clear transform CB before CS toggles to position:fixed panel.
        setCollapsed(end, false, false);
      },
      true
    );
  }

  function boot() {
    sync();
    bindCsOpenClearsEndTransform();
    if (typeof MutationObserver === 'function') {
      var obs = new MutationObserver(function () {
        scheduleSync();
      });
      obs.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class'],
      });
    }
  }

  global.WelineStorefrontFloatLayer = {
    sync: sync,
    setCollapsed: setCollapsed,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : this);
