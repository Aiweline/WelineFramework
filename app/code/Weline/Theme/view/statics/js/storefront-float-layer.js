/**
 * Storefront float-layer:
 * 1) adopt fallback — move legacy body-sibling music/CS into start/end slots
 * 2) edge dock — collapse every occupied slot to its viewport edge with localStorage
 *
 * Edge dock is attribute-driven: a slot opts in with `data-float-dock`
 * (`left|right|up|down`). All eight directions share this one code path.
 *
 * Owning module: Weline_Theme (mechanism). Widgets stay content-only.
 * Marker: storefront-float-edge-dock-v1
 * Marker: storefront-float-edge-transform-clear-v1
 * Marker: storefront-float-load-on-layer-v1
 * Marker: storefront-float-edge-dock-directions-v2
 */
(function (global) {
  'use strict';

  var LAYER_SEL = '#w-storefront-float-layer';
  var START_SEL = '[data-float-slot="start"]';
  var END_SEL = '[data-float-slot="end"]';
  var DOCK_SEL = '[data-float-dock]';
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

  function dockSlots() {
    var root = layer();
    if (!root) {
      return [];
    }
    // `data-float-dock` is the one opt-in attribute. Legacy `data-float-slot`
    // and published `data-slot-id` shells are normalised so old cached pages
    // keep their × without a rebuild.
    return root.querySelectorAll(
      '.w-storefront-float-layer__slot[data-float-dock], ' +
        '.w-storefront-float-layer__slot[data-float-slot], ' +
        '.w-storefront-float-layer__slot[data-slot-id]'
    );
  }

  function dockDir(slotEl) {
    return (slotEl.getAttribute('data-float-dock') || '').toLowerCase();
  }

  function inferDockDir(slotEl) {
    var legacy = (slotEl.getAttribute('data-float-slot') || '').toLowerCase();
    if (legacy === 'end' || legacy === 'side') {
      return 'right';
    }
    if (legacy === 'start') {
      return 'left';
    }
    var id = (slotEl.getAttribute('data-slot-id') || '').toLowerCase();
    if (/-top-center$/.test(id)) {
      return 'up';
    }
    if (/-bottom-center$/.test(id)) {
      return 'down';
    }
    if (/-end$|-side$/.test(id)) {
      return 'right';
    }
    if (/-start$/.test(id)) {
      return 'left';
    }
    return '';
  }

  function ensureDockDir(slotEl) {
    var dir = dockDir(slotEl);
    if (dir === 'left' || dir === 'right' || dir === 'up' || dir === 'down') {
      return dir;
    }
    dir = inferDockDir(slotEl);
    if (dir !== '') {
      slotEl.setAttribute('data-float-dock', dir);
    }
    return dir;
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

  function storageKey(slotEl) {
    // Each container keeps its own state: collapsing one must not collapse
    // another. Marker: storefront-float-edge-storage-key-v3
    var id = slotEl.getAttribute('data-slot-id') || '';
    if (id === '') {
      id = slotEl.getAttribute('data-float-slot') || dockDir(slotEl) || 'start';
    }
    return STORAGE_PREFIX + id;
  }

  function readStoredCollapsed(slotEl) {
    try {
      return !!(global.localStorage && global.localStorage.getItem(storageKey(slotEl)) === '1');
    } catch (e) {
      return false;
    }
  }

  function writeStoredCollapsed(slotEl, collapsed) {
    try {
      if (!global.localStorage) {
        return;
      }
      if (collapsed) {
        global.localStorage.setItem(storageKey(slotEl), '1');
      } else {
        global.localStorage.removeItem(storageKey(slotEl));
      }
    } catch (e) {
      /* ignore quota / private mode */
    }
  }

  function applyShift(slotEl, collapsed) {
    // Collapsed transforms live in CSS keyed by `data-float-dock`, so every
    // direction (including base translateY(-50%) containers) composes without
    // per-side JS. Expanded must clear any inline transform: a leftover
    // identity translate still creates a containing block so mobile CS
    // position:fixed panels size to the FAB slot and appear to vanish.
    // Marker: storefront-float-edge-transform-clear-v1
    if (!collapsed) {
      slotEl.style.transform = '';
      slotEl.style.removeProperty('transform');
    }
  }

  function slotHasDockableContent(slotEl) {
    var body = edgeBody(slotEl) || slotEl;
    if (!body) {
      return false;
    }
    var nodes = body.querySelectorAll('*');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (el.closest('[data-float-edge-chrome]')) {
        continue;
      }
      if (el.getAttribute('data-weline-mount-state') === 'empty') {
        continue;
      }
      // Real rendered content only: empty shells / skipped stubs are zero-size
      // and must NOT count, otherwise an orphan × sits on the cookie bar.
      // Marker: storefront-float-dock-real-content-v2
      var rect = el.getBoundingClientRect();
      if (rect.width > 1 || rect.height > 1) {
        return true;
      }
    }
    return false;
  }

  function ensureChrome(slotEl) {
    var dir = ensureDockDir(slotEl);
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
      chrome.setAttribute('data-float-edge-dir', dir);

      var labels = resolveEdgeLabels(slotEl);
      var dismissLabel = labels.dismiss;
      var recallLabel = labels.recall;

      var dismiss = document.createElement('button');
      dismiss.type = 'button';
      dismiss.className = 'w-storefront-float-edge__dismiss';
      dismiss.setAttribute('data-float-edge-dismiss', '');
      dismiss.setAttribute('data-testid', 'storefront-float-edge-dismiss-' + (slotEl.getAttribute('data-slot-id') || dir));
      dismiss.setAttribute('aria-label', dismissLabel);
      dismiss.title = dismissLabel;
      dismiss.innerHTML = '<span aria-hidden="true">×</span>';

      var recall = document.createElement('button');
      recall.type = 'button';
      recall.className = 'w-storefront-float-edge__recall';
      recall.setAttribute('data-float-edge-recall', '');
      recall.setAttribute('data-testid', 'storefront-float-edge-recall-' + (slotEl.getAttribute('data-slot-id') || dir));
      recall.setAttribute('aria-label', recallLabel);
      recall.title = recallLabel;
      recall.innerHTML =
        '<span class="w-storefront-float-edge__recall-icon" aria-hidden="true">' +
        recallGlyph(dir) +
        '</span>';

      chrome.appendChild(dismiss);
      chrome.appendChild(recall);
      slotEl.insertBefore(chrome, body);
    } else {
      chrome.setAttribute('data-float-edge-dir', dir);
    }

    return {
      body: body,
      chrome: chrome,
      dismiss: chrome.querySelector('[data-float-edge-dismiss]'),
      recall: chrome.querySelector('[data-float-edge-recall]'),
    };
  }

  function recallGlyph(dir) {
    if (dir === 'up') {
      return '▾';
    }
    if (dir === 'down') {
      return '▴';
    }
    return dir === 'left' ? '›' : '‹';
  }

  function readRuntimeFloatEdge() {
    try {
      var el = document.getElementById('weline-frontend-runtime-config');
      if (!el) {
        return null;
      }
      var cfg = JSON.parse(el.textContent || '{}');
      var fe = cfg && cfg.api ? cfg.api.floatEdge : null;
      if (!fe || typeof fe !== 'object') {
        return null;
      }
      return fe;
    } catch (e) {
      return null;
    }
  }

  function pageLocaleLooksChinese() {
    var html = document.documentElement;
    var lang = (
      (html && (html.getAttribute('data-lang') || html.getAttribute('lang'))) ||
      ''
    ).toLowerCase();
    return /^zh([_-]|$)/.test(lang);
  }

  function resolveEdgeLabels(slotEl) {
    var fe = readRuntimeFloatEdge();
    var dismiss =
      (fe && fe.dismiss) ||
      (slotEl && slotEl.getAttribute('data-edge-dismiss-label')) ||
      'Collapse float';
    var recall =
      (fe && fe.recall) ||
      (slotEl && slotEl.getAttribute('data-edge-recall-label')) ||
      'Expand float';
    if (!pageLocaleLooksChinese()) {
      if (dismiss === '收起悬浮') {
        dismiss = (fe && fe.dismiss) || 'Collapse float';
      }
      if (recall === '展开悬浮') {
        recall = (fe && fe.recall) || 'Expand float';
      }
    }
    return { dismiss: String(dismiss), recall: String(recall) };
  }

  function applyEdgeLabels(slotEl) {
    if (!slotEl) {
      return;
    }
    var labels = resolveEdgeLabels(slotEl);
    slotEl.setAttribute('data-edge-dismiss-label', labels.dismiss);
    slotEl.setAttribute('data-edge-recall-label', labels.recall);
    var dismissBtn = slotEl.querySelector('[data-float-edge-dismiss]');
    var recallBtn = slotEl.querySelector('[data-float-edge-recall]');
    if (dismissBtn) {
      dismissBtn.setAttribute('aria-label', labels.dismiss);
      dismissBtn.title = labels.dismiss;
    }
    if (recallBtn) {
      recallBtn.setAttribute('aria-label', labels.recall);
      recallBtn.title = labels.recall;
    }
  }

  function setCollapsed(slotEl, collapsed, persist) {
    var dir = ensureDockDir(slotEl);
    var parts = ensureChrome(slotEl);
    applyEdgeLabels(slotEl);
    var labels = resolveEdgeLabels(slotEl);
    var dismissLabel = labels.dismiss;
    var recallLabel = labels.recall;

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
        icon.textContent = recallGlyph(dir);
      }
    }

    if (persist !== false) {
      writeStoredCollapsed(slotEl, collapsed);
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
    applyEdgeLabels(slotEl);
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
    var want = readStoredCollapsed(slotEl);
    // CS chat is slot-anchored absolute; still expand end when open so a
    // collapsed slot (visibility:hidden body) cannot hide the in-slot panel.
    if (slotEl.getAttribute('data-float-slot') === 'end' && endHasOpenCustomerService(slotEl)) {
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
    var slots = dockSlots();
    for (var i = 0; i < slots.length; i++) {
      syncDock(slots[i]);
    }
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
      // Footer height changes when widgets mount/expand; keep the clearance exact.
      measureFooterLift();
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
        // Expand end before CS opens so the slot-anchored panel stays visible.
        setCollapsed(end, false, false);
      },
      true
    );
  }

  function measureHeaderOffset() {
    var header = document.querySelector('[data-testid="ca-header"], .ca-header, header');
    if (!header) {
      return;
    }
    var rect = header.getBoundingClientRect();
    if (rect.height <= 0) {
      return;
    }
    // Top slots anchor below the real chrome (announce bar + header). The CSS
    // fallback (4.5rem) only knows the header itself, so any announce/topbar
    // above it made the slot overlap the header.
    // Marker: storefront-float-header-offset-v1
    var bottom = rect.bottom + (global.scrollY || global.pageYOffset || 0);
    if (bottom > 0) {
      document.documentElement.style.setProperty(
        '--weline-header-height',
        Math.round(bottom) + 'px'
      );
    }
  }

  // Footer clearance: a fixed float must never sit on top of footer content.
  // While the footer is in view the layer's bottom inset is raised to the
  // footer's viewport top, so bottom-anchored slots stop above the footer.
  // Marker: storefront-float-footer-clearance-v1
  var footerLiftScheduled = false;

  function measureFooterLift() {
    var layerEl = layer();
    if (!layerEl) {
      return;
    }
    var footer = document.querySelector('footer, [data-testid="ca-footer"]');
    var lift = 0;
    if (footer) {
      var viewport = global.innerHeight || document.documentElement.clientHeight || 0;
      var clearance =
        parseFloat(
          global.getComputedStyle(layerEl).getPropertyValue('--weline-product-sticky-purchase-clearance')
        ) || 0;
      lift = Math.round(viewport - clearance - footer.getBoundingClientRect().top);
      if (lift < 0) {
        lift = 0;
      } else if (lift > viewport) {
        lift = viewport;
      }
    }
    if (lift > 0) {
      layerEl.style.transition = 'none';
      layerEl.style.bottom =
        'calc(var(--weline-product-sticky-purchase-clearance, 0px) + ' + lift + 'px)';
    } else {
      layerEl.style.removeProperty('transition');
      layerEl.style.removeProperty('bottom');
    }
  }

  function scheduleFooterLift() {
    if (footerLiftScheduled) {
      return;
    }
    footerLiftScheduled = true;
    var run = function () {
      footerLiftScheduled = false;
      measureFooterLift();
    };
    if (typeof global.requestAnimationFrame === 'function') {
      global.requestAnimationFrame(run);
    } else {
      setTimeout(run, 16);
    }
  }

  function boot() {
    measureHeaderOffset();
    measureFooterLift();
    global.addEventListener('resize', function () {
      measureHeaderOffset();
      measureFooterLift();
    });
    global.addEventListener('scroll', scheduleFooterLift, { passive: true });
    if (document.readyState === 'complete') {
      global.setTimeout(measureHeaderOffset, 300);
      global.setTimeout(measureFooterLift, 300);
    } else {
      global.addEventListener('load', function () {
        global.setTimeout(measureHeaderOffset, 300);
        global.setTimeout(measureFooterLift, 300);
      }, { once: true });
    }
    sync();
    bindCsOpenClearsEndTransform();
    var observeOptions = {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class'],
    };
    var observeApi = (global.Weline && global.Weline.dom && typeof global.Weline.dom.observe === 'function')
      ? global.Weline.dom.observe.bind(global.Weline.dom)
      : (global.Weline && typeof global.Weline.observeMutationsCoalesced === 'function'
        ? global.Weline.observeMutationsCoalesced
        : null);
    if (observeApi && document.documentElement) {
      try {
        observeApi({
          target: document.documentElement,
          options: observeOptions,
          idleTimeoutMs: 100,
          label: 'storefront-float-layer',
          onFlush: scheduleSync,
        });
        return;
      } catch (_e) { /* fall through */ }
    }
    /* ARCH_MO_FALLBACK_START */
    if (typeof MutationObserver === 'function' && document.documentElement) {
      var obs = new MutationObserver(function () {
        try { obs.disconnect(); } catch (_d) { /* ignore */ }
        global.setTimeout(function () {
          scheduleSync();
          try {
            obs.observe(document.documentElement, observeOptions);
          } catch (_o) { /* ignore */ }
        }, 100);
      });
      try {
        obs.observe(document.documentElement, observeOptions);
      } catch (_b) { /* ignore */ }
    }
    /* ARCH_MO_FALLBACK_END */
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
