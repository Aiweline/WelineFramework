<?php

declare(strict_types=1);

namespace Weline\Framework\View\Taglib\Support;

/**
 * Taglib 浮层定位发射器：输出自洽 JS 闭包，不依赖 Theme.js 的 WelineSmartDropdown。
 *
 * 业务 Taglib 在自身输出中调用 {@see self::script()}，然后使用
 * window.WelineTaglibFloatingDropdown.place / mount / unmount。
 */
final class FloatingDropdownEmitter
{
    public const GLOBAL_NAME = 'WelineTaglibFloatingDropdown';
    public const HOVER_BRIDGE_ATTR = 'data-weline-taglib-hover-bridge';
    public const SCRIPT_MARKER = 'data-weline-taglib-floating-dropdown';

    /**
     * 幂等注入完整 &lt;script&gt;；同页多次输出时由 JS 守卫短路。
     */
    public static function script(): string
    {
        return '<script ' . self::SCRIPT_MARKER . '="1">' . self::javaScript() . '</script>';
    }

    /**
     * 仅 JS 源（无 script 标签），便于嵌入既有标签脚本块开头。
     */
    public static function javaScript(): string
    {
        $global = self::GLOBAL_NAME;
        $bridgeAttr = self::HOVER_BRIDGE_ATTR;

        return <<<JS
(function(window,document){
  'use strict';
  if (window.{$global} && window.{$global}.version) { return; }
  var HOVER_BRIDGE_ATTR = '{$bridgeAttr}';
  function numberOr(value, fallback) {
    var parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
  }
  function viewportRect() {
    var viewport = window.visualViewport;
    var left = viewport ? viewport.offsetLeft : 0;
    var top = viewport ? viewport.offsetTop : 0;
    var width = viewport ? viewport.width : (document.documentElement.clientWidth || window.innerWidth || 0);
    var height = viewport ? viewport.height : (document.documentElement.clientHeight || window.innerHeight || 0);
    return { left: left, top: top, right: left + width, bottom: top + height, width: width, height: height };
  }
  function resolveScrollContainer(panel, value) {
    if (!value) { return null; }
    if (typeof value === 'string') { return panel.querySelector(value); }
    return value;
  }
  function shouldHoverBridge(anchor, panel, config) {
    if (config.hoverBridge === false) { return false; }
    if (config.hoverBridge === true) { return true; }
    return !!(anchor && panel && typeof anchor.contains === 'function' && anchor.contains(panel));
  }
  function clearHoverBridge(anchor) {
    if (!anchor || !anchor.querySelectorAll) { return; }
    var bridges = anchor.querySelectorAll('[' + HOVER_BRIDGE_ATTR + ']');
    for (var i = 0; i < bridges.length; i++) { bridges[i].remove(); }
  }
  function ensureHoverBridge(anchor, panel, placement, gap) {
    if (!anchor) { return; }
    var size = Math.max(0, Math.ceil(numberOr(gap, 0)));
    if (size <= 0) { clearHoverBridge(anchor); return; }
    var anchorStyle = window.getComputedStyle(anchor);
    if (anchorStyle.position === 'static') { anchor.style.position = 'relative'; }
    var bridge = null;
    var existing = anchor.querySelectorAll(':scope > [' + HOVER_BRIDGE_ATTR + ']');
    if (existing.length) {
      bridge = existing[0];
      for (var i = 1; i < existing.length; i++) { existing[i].remove(); }
    } else {
      bridge = document.createElement('div');
      bridge.setAttribute(HOVER_BRIDGE_ATTR, '');
      bridge.setAttribute('aria-hidden', 'true');
      if (panel && panel.parentNode === anchor) { anchor.insertBefore(bridge, panel); }
      else { anchor.appendChild(bridge); }
    }
    var leftPx = 0, rightPx = 0;
    if (panel && typeof panel.getBoundingClientRect === 'function') {
      var anchorRect = anchor.getBoundingClientRect();
      var panelRect = panel.getBoundingClientRect();
      var unionLeft = Math.min(anchorRect.left, panelRect.left);
      var unionRight = Math.max(anchorRect.right, panelRect.right);
      leftPx = Math.round(unionLeft - anchorRect.left);
      rightPx = Math.round(anchorRect.right - unionRight);
    }
    bridge.style.position = 'absolute';
    bridge.style.left = leftPx + 'px';
    bridge.style.right = rightPx + 'px';
    bridge.style.width = 'auto';
    bridge.style.height = size + 'px';
    bridge.style.pointerEvents = 'auto';
    bridge.style.background = 'transparent';
    bridge.style.zIndex = '1';
    if (placement === 'top') {
      bridge.style.top = 'auto';
      bridge.style.bottom = '100%';
    } else {
      bridge.style.bottom = 'auto';
      bridge.style.top = '100%';
    }
  }
  function compute(anchorRect, panelRect, panel, config) {
    config = config || {};
    var viewport = viewportRect();
    var margin = Math.max(0, numberOr(config.margin, 8));
    var gap = Math.max(0, numberOr(config.gap, 4));
    var availableWidth = Math.max(0, viewport.width - margin * 2);
    var configuredMinWidth = Math.max(0, numberOr(config.minWidth, 0));
    var configuredMaxWidth = Math.max(0, numberOr(config.maxWidth, availableWidth));
    var naturalWidth = Math.max(anchorRect.width || 0, panelRect.width || 0, panel.scrollWidth || 0, configuredMinWidth);
    var width = Math.min(naturalWidth, availableWidth, configuredMaxWidth || availableWidth);
    var naturalHeight = Math.max(panel.scrollHeight || 0, panelRect.height || 0);
    var preferredHeight = Math.max(0, numberOr(config.preferredHeight, naturalHeight));
    var heightTarget = Math.min(naturalHeight || preferredHeight, preferredHeight || naturalHeight);
    var belowSpace = Math.max(0, viewport.bottom - margin - anchorRect.bottom - gap);
    var aboveSpace = Math.max(0, anchorRect.top - viewport.top - margin - gap);
    var openUp = belowSpace < heightTarget && aboveSpace > belowSpace;
    var availableHeight = openUp ? aboveSpace : belowSpace;
    var maxHeight = Math.max(0, Math.floor(Math.min(heightTarget || availableHeight, availableHeight)));
    var renderedHeight = Math.min(naturalHeight || maxHeight, maxHeight);
    var align = (config.align === 'end' || config.align === 'center') ? config.align : 'start';
    var left = anchorRect.left;
    if (align === 'end') { left = anchorRect.right - width; }
    else if (align === 'center') { left = anchorRect.left + (anchorRect.width - width) / 2; }
    left = Math.min(Math.max(left, viewport.left + margin), Math.max(viewport.left + margin, viewport.right - margin - width));
    var top = openUp ? (anchorRect.top - gap - renderedHeight) : (anchorRect.bottom + gap);
    top = Math.min(Math.max(top, viewport.top + margin), Math.max(viewport.top + margin, viewport.bottom - margin - renderedHeight));
    return { left: left, top: top, width: width, maxHeight: maxHeight, placement: openUp ? 'top' : 'bottom', gap: gap, viewport: viewport };
  }
  function place(anchor, panel, config) {
    config = config || {};
    if (!anchor || !panel) { return null; }
    if (typeof panel.__welineTaglibDropdownOriginalStyle === 'undefined') {
      panel.__welineTaglibDropdownOriginalStyle = panel.getAttribute('style');
    }
    panel.style.boxSizing = 'border-box';
    panel.style.position = 'fixed';
    panel.style.right = 'auto';
    panel.style.bottom = 'auto';
    panel.style.margin = '0';
    panel.style.width = '';
    panel.style.minWidth = '';
    panel.style.maxWidth = '';
    panel.style.maxHeight = '';
    var anchorRect = anchor.getBoundingClientRect();
    var panelRect = panel.getBoundingClientRect();
    var next = compute(anchorRect, panelRect, panel, config);
    panel.style.left = Math.round(next.left) + 'px';
    panel.style.top = Math.round(next.top) + 'px';
    panel.style.width = Math.round(next.width) + 'px';
    panel.style.minWidth = Math.round(next.width) + 'px';
    panel.style.maxWidth = Math.round(next.width) + 'px';
    panel.style.maxHeight = Math.round(next.maxHeight) + 'px';
    panel.style.zIndex = String(numberOr(config.zIndex, 4200));
    panel.setAttribute('data-weline-taglib-dropdown-placement', next.placement);
    var scrollContainer = resolveScrollContainer(panel, config.scrollContainer);
    if (scrollContainer) {
      var chromeHeight = Math.max(0, (panel.scrollHeight || 0) - (scrollContainer.scrollHeight || 0));
      scrollContainer.style.maxHeight = Math.max(0, next.maxHeight - chromeHeight) + 'px';
      panel.style.overflowY = config.overflowY || 'hidden';
    } else if (config.overflowY) {
      panel.style.overflowY = config.overflowY;
    }
    if (shouldHoverBridge(anchor, panel, config)) {
      ensureHoverBridge(anchor, panel, next.placement, next.gap);
    } else {
      clearHoverBridge(anchor);
    }
    return next;
  }
  function mount(anchor, panel, config) {
    config = config || {};
    if (!anchor || !panel) { return null; }
    if (!panel.__welineTaglibDropdownOriginalParent) {
      panel.__welineTaglibDropdownOriginalParent = panel.parentNode || null;
      panel.__welineTaglibDropdownOriginalNext = panel.nextSibling || null;
    }
    if (typeof panel.__welineTaglibDropdownOriginalStyle === 'undefined') {
      panel.__welineTaglibDropdownOriginalStyle = panel.getAttribute('style');
    }
    if (config.portal !== false && panel.parentNode !== document.body) {
      document.body.appendChild(panel);
    }
    panel.hidden = false;
    panel.style.display = config.display || 'block';
    return place(anchor, panel, config);
  }
  function unmount(panel) {
    if (!panel) { return; }
    var parent = panel.__welineTaglibDropdownOriginalParent;
    var next = panel.__welineTaglibDropdownOriginalNext;
    if (parent) {
      if (next && next.parentNode === parent) { parent.insertBefore(panel, next); }
      else { parent.appendChild(panel); }
    }
    var originalStyle = panel.__welineTaglibDropdownOriginalStyle;
    if (originalStyle === null || typeof originalStyle === 'undefined') { panel.removeAttribute('style'); }
    else { panel.setAttribute('style', originalStyle); }
    panel.hidden = true;
    panel.removeAttribute('data-weline-taglib-dropdown-placement');
    clearHoverBridge(parent);
    if (panel.parentNode && panel.parentNode !== parent) { clearHoverBridge(panel.parentNode); }
  }
  window.{$global} = { version: '1.0.0', compute: compute, place: place, mount: mount, unmount: unmount };
})(window, document);
JS;
    }
}
