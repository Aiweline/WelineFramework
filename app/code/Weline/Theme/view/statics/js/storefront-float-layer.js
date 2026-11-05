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
  // 偏好值带版本：旧实现只写裸 `'1'`，无版本、无 TTL，且收起态连召回芯片都不出现
  // （2026-11-05 实测：值 '1' 时 panel 整体移到屏幕外、recall hidden）⇒ 用户点过一次 ×
  // 就永久看不到悬浮层，只能手清 localStorage。读到旧值一律视为过期偏好并自愈展开。
  // 键前缀不得改（dev/session/float-edge-dock-recall-fix 明令）；版本化只改值的格式。
  // Marker: storefront-float-edge-collapsed-storage-version-v1
  var STORAGE_VERSION = 2;
  var LEGACY_COLLAPSED_VALUE = '1';
  var boundSlots = typeof WeakSet === 'function' ? new WeakSet() : null;
  var syncScheduled = false;
  var syncing = false;
  // 事件驱动架构：观察面只认 layer 容器的 childList/subtree，不认任何 attribute。
  // 本模块自写的 class/style/attribute 结构上不可能再被自己观察到，
  // 自触发循环在架构层不可能发生（取代 storefront-float-self-mute-sync-v1 时间门补丁）。
  // 内容晚到 → 一次 flush；sync 幂等，下一次 flush 无写入即静默收敛。
  // Marker: storefront-float-self-mute-sync-v1 (superseded: time-gate removed)
  // Marker: storefront-float-observer-content-only-v1

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
    // Marker: storefront-float-edge-collapsed-storage-version-v1
    try {
      if (!global.localStorage) {
        return false;
      }
      var raw = global.localStorage.getItem(storageKey(slotEl));
      if (!raw) {
        return false;
      }
      if (raw === LEGACY_COLLAPSED_VALUE) {
        // 旧值无法区分「用户主动收起」与「历史 bug 写入」：一次删除即自愈为展开。
        global.localStorage.removeItem(storageKey(slotEl));
        return false;
      }
      var parsed = JSON.parse(raw);
      return !!(parsed && parsed.v === STORAGE_VERSION && parsed.collapsed === true);
    } catch (e) {
      // 非法值（含 JSON 解析失败）同样清除，避免永久不可见。
      try {
        global.localStorage.removeItem(storageKey(slotEl));
      } catch (e2) {
        /* ignore quota / private mode */
      }
      return false;
    }
  }

  function writeStoredCollapsed(slotEl, collapsed) {
    try {
      if (!global.localStorage) {
        return;
      }
      if (collapsed) {
        global.localStorage.setItem(
          storageKey(slotEl),
          JSON.stringify({ v: STORAGE_VERSION, collapsed: true })
        );
      } else {
        global.localStorage.removeItem(storageKey(slotEl));
      }
    } catch (e) {
      /* ignore quota / private mode */
    }
  }

  function applyShift(slotEl, collapsed) {
    // Collapsed transforms live in CSS (critical + sheet), keyed by `data-float-dock`.
    // Recall chip stays on the slot exterior (left:100% / right:100%); geometry is
    // full ±100% shift — see Theme/doc/店面浮动层贴边收起契约.md.
    // Expanded must clear any inline transform: a leftover identity translate still
    // creates a containing block so mobile CS position:fixed panels size to the
    // FAB slot and appear to vanish.
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
    // is-edge-expanded 不是本机制状态机的一部分（历史 keepSlotExpanded 残留）；展开/收起只认 collapsed。
    slotEl.classList.remove('is-edge-expanded');
    slotEl.setAttribute('data-edge-collapsed', collapsed ? '1' : '0');
    applyShift(slotEl, collapsed);

    if (parts.body) {
      parts.body.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
      if ('inert' in parts.body) {
        parts.body.inert = collapsed;
      }
    }

    // 异步部件晚于 dock 初始化注入时可能不在 __body 内：除 edge chrome 外的槽位
    // 子节点内联隐藏（展开还原）。几何与 visibility 主路径仍由 CSS 承担。
    // Marker: storefront-float-collapse-inline-children-v1
    for (var i = 0; i < slotEl.children.length; i++) {
      var child = slotEl.children[i];
      if (!child || (child.hasAttribute && child.hasAttribute('data-float-edge-chrome'))) {
        continue;
      }
      if (collapsed) {
        if (!child.hasAttribute('data-float-collapse-hidden')) {
          child.setAttribute('data-float-collapse-hidden', '1');
          child.setAttribute('data-float-collapse-prev', child.getAttribute('style') || '');
        }
        child.style.visibility = 'hidden';
        child.style.opacity = '0';
        child.style.pointerEvents = 'none';
      } else if (child.hasAttribute('data-float-collapse-hidden')) {
        child.setAttribute('style', child.getAttribute('data-float-collapse-prev') || '');
        child.removeAttribute('data-float-collapse-hidden');
        child.removeAttribute('data-float-collapse-prev');
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

  function healCollapsedChildren(slotEl) {
    if (!slotEl) {
      return;
    }
    for (var i = 0; i < slotEl.children.length; i++) {
      var child = slotEl.children[i];
      if (!child || (child.hasAttribute && child.hasAttribute('data-float-edge-chrome'))) {
        continue;
      }
      if (!child.hasAttribute('data-float-collapse-hidden')) {
        child.setAttribute('data-float-collapse-hidden', '1');
        child.setAttribute('data-float-collapse-prev', child.getAttribute('style') || '');
      }
      child.style.visibility = 'hidden';
      child.style.opacity = '0';
      child.style.pointerEvents = 'none';
    }
  }

  function clearDockVisual(slotEl) {
    var chrome = slotEl.querySelector('[data-float-edge-chrome]');
    var alreadyClear =
      !slotEl.classList.contains('is-edge-collapsed') &&
      !slotEl.classList.contains('is-edge-expanded') &&
      !slotEl.hasAttribute('data-edge-collapsed') &&
      !slotEl.style.transform &&
      (!chrome || chrome.hidden);
    if (alreadyClear) {
      return;
    }
    slotEl.classList.remove('is-edge-collapsed');
    slotEl.classList.remove('is-edge-expanded');
    slotEl.removeAttribute('data-edge-collapsed');
    slotEl.style.transform = '';
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
    // 异步部件（全局区水合）晚于 dock 初始化注入内容：若此时没有 edge body，
    // 内容会留在槽位本身，收起态只隐藏 __body 就会「收起了还在展示」。
    // 这里强制重建外壳，把新内容收进 __body，后续 setCollapsed 才能真隐藏。
    if (!edgeBody(slotEl)) {
      if (boundSlots) {
        boundSlots.delete(slotEl);
      }
      bindDock(slotEl);
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
    } else if (want) {
      // 已是收起态：只把晚到的子节点藏起来，禁止再 toggle class（避免 document MO 自触发）。
      healCollapsedChildren(slotEl);
    } else {
      // Re-assert clear transform even when class already expanded (legacy
      // identity-translate inline from older builds).
      applyShift(slotEl, false);
      // 幂等：classList.remove 对不存在的类仍会发 attribute 变更记录；先查再删。
      if (slotEl.classList.contains('is-edge-expanded')) {
        slotEl.classList.remove('is-edge-expanded');
      }
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
    ensureLayerContentObserver();
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
    // 幂等收敛：sync() 只在状态漂移时写 DOM；重复 flush 第二次无写入即静默。
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
    // Only a laid-out page footer may lift the layer. A zero-height match
    // (e.g. a hidden mini-cart drawer footer at top:0) would compute a
    // full-viewport lift, flatten the layer to 0 height and push every slot
    // off-screen. Marker: storefront-float-footer-real-v1
    var footers = document.querySelectorAll(
      'footer, [data-testid="ca-footer"], [data-testid="footer-container"]'
    );
    var footer = null;
    for (var fi = 0; fi < footers.length; fi++) {
      if (footers[fi].getBoundingClientRect().height >= 1) {
        footer = footers[fi];
        break;
      }
    }
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

  var layerContentObserverEl = null;

  function ensureLayerContentObserver() {
    var layerEl = layer();
    if (!layerEl || layerEl === layerContentObserverEl) {
      return;
    }
    layerContentObserverEl = layerEl;
    // 只观察内容插入（childList/subtree），不观察任何 attribute。
    // 本模块自写的 class/style/attribute 不在观察面内：
    // 自触发循环在结构上不可能发生（取代 document 级 class 观察 + muteUntil 时间门）。
    // 自写 childList（adoptOne/chrome 插入）最多触发一次幂等收敛 flush。
    // Marker: storefront-float-observer-content-only-v1
    var observeOptions = { childList: true, subtree: true };
    var observeApi = (global.Weline && global.Weline.dom && typeof global.Weline.dom.observe === 'function')
      ? global.Weline.dom.observe.bind(global.Weline.dom)
      : (global.Weline && typeof global.Weline.observeMutationsCoalesced === 'function'
        ? global.Weline.observeMutationsCoalesced
        : null);
    if (observeApi) {
      try {
        observeApi({
          target: layerEl,
          options: observeOptions,
          idleTimeoutMs: 100,
          label: 'storefront-float-layer',
          onFlush: scheduleSync,
        });
        return;
      } catch (_e) { /* fall through */ }
    }
    if (typeof MutationObserver === 'function') {
      /* ARCH_MO_FALLBACK_START */
      var obs = new MutationObserver(function () {
        scheduleSync();
      });
      obs.observe(layerEl, observeOptions);
      /* ARCH_MO_FALLBACK_END */
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
    // 事件驱动入口：slot 内容填充由 global-region-async 派发（bubbles），
    // 这里只订阅一次 document 事件——不观察任何 attribute。
    // layer 晚建时 sync() 不 attach，由本事件回调补 attach + sync。
    document.addEventListener('weline:float-slot-filled', function () {
      ensureLayerContentObserver();
      scheduleSync();
    });
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
