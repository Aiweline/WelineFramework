<?php

declare(strict_types=1);

namespace Weline\Websites\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

class RegistrarSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'websites:registrar:select';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => true,
            'name' => true,
            'value' => false,
            'display' => false,
            'class' => false,
            'style' => false,
            'placeholder' => false,
            'empty-label' => false,
            'options' => false,
            'multiple' => false,
            'on-select' => false,
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }

            $placeholder = (string)($attributes['placeholder'] ?? __('搜索服务商...'));
            $emptyLabel = (string)($attributes['empty-label'] ?? __('请选择服务商'));
            $class = (string)($attributes['class'] ?? '');
            $style = (string)($attributes['style'] ?? '');
            $multipleRaw = (string)($attributes['multiple'] ?? 'false');
            $isMultiple = in_array(strtolower(trim($multipleRaw)), ['true', '1', 'yes'], true);
            $onSelect = (string)($attributes['on-select'] ?? '');

            $attrs = $attributes;
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);
            $multiFlag = $isMultiple ? 'true' : 'false';

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';
            $html[] = '<style>';
            $html[] = '.weline-registrar-select{position:relative}';
            $html[] = '.weline-registrar-trigger{display:flex;align-items:center;justify-content:space-between;width:100%;min-height:38px;padding:.375rem .75rem;background:var(--backend-color-card-bg,#fff);border:1px solid var(--backend-color-border-default,#ced4da);border-radius:var(--backend-border-radius-sm,.25rem);color:var(--backend-color-text-primary,#212529);cursor:pointer;transition:border-color .15s ease,box-shadow .15s ease}';
            $html[] = '.weline-registrar-trigger:hover{border-color:var(--backend-color-primary,#556ee6)}';
            $html[] = '.weline-registrar-tags{display:flex;flex-wrap:wrap;gap:.25rem;align-items:center;flex:1}';
            $html[] = '.weline-registrar-tag{display:inline-flex;align-items:center;padding:.125rem .5rem;font-size:.75rem;background:var(--backend-color-primary-bg-subtle,rgba(85,110,230,.1));color:var(--backend-color-primary,#556ee6);border-radius:var(--backend-border-radius-sm,.25rem)}';
            $html[] = '.weline-registrar-tag-remove{margin-left:.25rem;cursor:pointer;opacity:.7}';
            $html[] = '.weline-registrar-dropdown{position:absolute;top:calc(100% + 4px);z-index:3000;padding:.75rem;background:var(--backend-color-card-bg,#fff);border:1px solid var(--backend-color-border-default,#dee2e6);border-radius:var(--backend-border-radius-md,.375rem);box-shadow:var(--backend-shadow-lg,0 .5rem 1rem rgba(0,0,0,.15));min-width:200px;max-width:350px}';
            $html[] = '.weline-registrar-search{width:100%;padding:.5rem .75rem;margin-bottom:.5rem;border:1px solid var(--backend-color-border-default,#dee2e6);border-radius:var(--backend-border-radius-sm,.25rem)}';
            $html[] = '.weline-registrar-list{max-height:280px;overflow-y:auto;border:1px solid var(--backend-color-border-default,#dee2e6);border-radius:var(--backend-border-radius-sm,.25rem);background:var(--backend-color-card-bg,#fff)}';
            $html[] = '.weline-registrar-group{padding:.45rem .75rem;font-weight:600;font-size:.75rem;text-transform:uppercase;color:var(--backend-color-text-secondary,#6c757d);background:var(--backend-color-bg-secondary,#f8f9fa);border-bottom:1px solid var(--backend-color-border-default,#dee2e6)}';
            $html[] = '.weline-registrar-item{padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid var(--backend-color-border-light,#e9ecef)}';
            $html[] = '.weline-registrar-item:last-child{border-bottom:none}';
            $html[] = '.weline-registrar-item:hover{background:var(--backend-color-bg-tertiary,#f1f3f5)}';
            $html[] = '.weline-registrar-item.active{background:var(--backend-color-primary-bg-subtle,rgba(85,110,230,.1))}';
            $html[] = '.weline-registrar-item-meta{font-size:.75rem;color:var(--backend-color-text-muted,#94a3b8)}';
            $html[] = '.weline-registrar-empty{padding:1rem;text-align:center;color:var(--backend-color-text-secondary,#6c757d)}';
            $html[] = '</style>';

            $html[] = '<div class="weline-registrar-select ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '" id="<?= htmlspecialchars($Taglib__id, ENT_QUOTES, \'UTF-8\') ?>_wrapper" data-multiple="' . $multiFlag . '">';
            $html[] = '  <button type="button" class="weline-registrar-trigger" id="<?= htmlspecialchars($Taglib__id, ENT_QUOTES, \'UTF-8\') ?>_trigger">';
            $html[] = '      <div class="weline-registrar-tags" id="<?= htmlspecialchars($Taglib__id, ENT_QUOTES, \'UTF-8\') ?>_tags"><span id="<?= htmlspecialchars($Taglib__id, ENT_QUOTES, \'UTF-8\') ?>_display"><?= htmlspecialchars(trim((string)$Taglib__display, "\'\"") !== \'\' ? trim((string)$Taglib__display, "\'\"") : \'' . addslashes($emptyLabel) . '\', ENT_QUOTES, \'UTF-8\') ?></span></div>';
            $html[] = '      <i class="mdi mdi-chevron-down"></i>';
            $html[] = '  </button>';
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($Taglib__id, ENT_QUOTES, \'UTF-8\') ?>_value" name="<?= htmlspecialchars($Taglib__name, ENT_QUOTES, \'UTF-8\') ?>" value="<?= htmlspecialchars((string)$Taglib__value, ENT_QUOTES, \'UTF-8\') ?>">';
            $html[] = '  <div class="weline-registrar-dropdown" id="<?= htmlspecialchars($Taglib__id, ENT_QUOTES, \'UTF-8\') ?>_dropdown" style="display:none;">';
            $html[] = '      <input type="text" class="weline-registrar-search" id="<?= htmlspecialchars($Taglib__id, ENT_QUOTES, \'UTF-8\') ?>_search" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '" autocomplete="off">';
            $html[] = '      <div class="weline-registrar-list" id="<?= htmlspecialchars($Taglib__id, ENT_QUOTES, \'UTF-8\') ?>_list"><div class="weline-registrar-empty">' . htmlspecialchars((string)__('加载中...'), ENT_QUOTES, 'UTF-8') . '</div></div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var id = <?= json_encode((string)$Taglib__id, JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var isMultiple = ' . ($isMultiple ? 'true' : 'false') . ';';
            $html[] = 'var emptyLabel = ' . json_encode($emptyLabel, JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var onSelectFn = ' . json_encode($onSelect, JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var optionsRaw = <?= json_encode($Taglib__options ?? "[]", JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var trigger = document.getElementById(id + "_trigger");';
            $html[] = 'var dropdown = document.getElementById(id + "_dropdown");';
            $html[] = 'var search = document.getElementById(id + "_search");';
            $html[] = 'var list = document.getElementById(id + "_list");';
            $html[] = 'var display = document.getElementById(id + "_display");';
            $html[] = 'var tags = document.getElementById(id + "_tags");';
            $html[] = 'var hidden = document.getElementById(id + "_value");';
            $html[] = 'var options = [];';
            $html[] = 'var selected = [];';
            $html[] = 'var floatingOpened = false;';
            $html[] = 'if (!window.WelineSmartDropdown) {';
            $html[] = '  window.WelineSmartDropdown = (function(){';
            $html[] = '    function compute(anchorRect, panelRect, cfg){';
            $html[] = '      var margin = cfg.margin || 8, gap = cfg.gap || 4;';
            $html[] = '      var vw = window.innerWidth || document.documentElement.clientWidth || 1200;';
            $html[] = '      var vh = window.innerHeight || document.documentElement.clientHeight || 900;';
            $html[] = '      var width = cfg.maxWidth ? Math.min(cfg.maxWidth, Math.max(cfg.minWidth || 0, panelRect.width || anchorRect.width)) : Math.max(cfg.minWidth || 0, panelRect.width || anchorRect.width);';
            $html[] = '      var height = panelRect.height || 0;';
            $html[] = '      var bottomSpace = vh - anchorRect.bottom - margin;';
            $html[] = '      var topSpace = anchorRect.top - margin;';
            $html[] = '      var openUp = bottomSpace < Math.min(cfg.preferredHeight || 260, height) && topSpace > bottomSpace;';
            $html[] = '      var top = openUp ? (anchorRect.top - height - gap) : (anchorRect.bottom + gap);';
            $html[] = '      if (top < margin) top = margin;';
            $html[] = '      if (top + height > vh - margin) top = Math.max(margin, vh - margin - height);';
            $html[] = '      var left = anchorRect.left;';
            $html[] = '      if (left + width > vw - margin) left = Math.max(margin, vw - margin - width);';
            $html[] = '      if (left < margin) left = margin;';
            $html[] = '      return { left:left, top:top, width:width, maxHeight:Math.max(160, vh - margin * 2), openUp:openUp };';
            $html[] = '    }';
            $html[] = '    return {';
            $html[] = '      mount:function(anchor,panel,cfg){';
            $html[] = '        cfg = cfg || {}; if (!anchor || !panel) return null;';
            $html[] = '        if (!panel.__welineOriginalParent) { panel.__welineOriginalParent = panel.parentNode || null; panel.__welineOriginalNext = panel.nextSibling || null; }';
            $html[] = '        panel.style.position = "fixed"; panel.style.zIndex = String(cfg.zIndex || 4000); panel.style.left = "0px"; panel.style.top = "0px";';
            $html[] = 'var anchorW = Math.round(anchor.getBoundingClientRect().width); var minW = cfg.maxWidth ? Math.min(cfg.minWidth || 0, cfg.maxWidth) : Math.max(cfg.minWidth || 0, anchorW); panel.style.minWidth = minW + "px"; panel.style.maxWidth = cfg.maxWidth ? cfg.maxWidth + "px" : "calc(100vw - 16px)";';
            $html[] = '        document.body.appendChild(panel); panel.style.display = "block";';
            $html[] = '        var rect = anchor.getBoundingClientRect(); var pr = panel.getBoundingClientRect(); var next = compute(rect, pr, cfg);';
            $html[] = '        panel.style.left = Math.round(next.left) + "px"; panel.style.top = Math.round(next.top) + "px"; panel.style.width = Math.round(next.width) + "px"; panel.style.maxHeight = Math.round(next.maxHeight) + "px";';
            $html[] = '        return next;';
            $html[] = '      },';
            $html[] = '      unmount:function(panel){';
            $html[] = '        if (!panel) return; panel.style.display = "none";';
            $html[] = '        if (panel.__welineOriginalParent) {';
            $html[] = '          if (panel.__welineOriginalNext && panel.__welineOriginalNext.parentNode === panel.__welineOriginalParent) { panel.__welineOriginalParent.insertBefore(panel, panel.__welineOriginalNext); }';
            $html[] = '          else { panel.__welineOriginalParent.appendChild(panel); }';
            $html[] = '        }';
            $html[] = '      }';
            $html[] = '    };';
            $html[] = '  })();';
            $html[] = '}';

            $html[] = 'function parseOptions(raw){';
            $html[] = '  if (Array.isArray(raw)) return raw;';
            $html[] = '  if (typeof raw !== "string" || !raw.trim()) return [];';
            $html[] = '  try { var parsed = JSON.parse(raw); return Array.isArray(parsed) ? parsed : []; } catch(e) { return []; }';
            $html[] = '}';
            $html[] = 'function normalizeOptions(items){';
            $html[] = '  return (items || []).map(function(item){';
            $html[] = '    return {';
            $html[] = '      value: String(item.value || ""),';
            $html[] = '      label: String(item.label || item.value || ""),';
            $html[] = '      group: String(item.group || ""),';
            $html[] = '      meta: String(item.meta || ""),';
            $html[] = '      raw: item';
            $html[] = '    };';
            $html[] = '  }).filter(function(item){ return item.value !== ""; });';
            $html[] = '}';
            $html[] = 'function readInitialSelection(){';
            $html[] = '  var raw = String(hidden.value || "").trim();';
            $html[] = '  if (!raw) return [];';
            $html[] = '  return raw.split(",").map(function(v){ return v.trim(); }).filter(function(v){ return v !== ""; });';
            $html[] = '}';
            $html[] = 'function emitChange(item){';
            $html[] = '  try { hidden.dispatchEvent(new Event("change", { bubbles: true })); } catch(e) {}';
            $html[] = '  if (onSelectFn && typeof window[onSelectFn] === "function") {';
            $html[] = '    window[onSelectFn](item || null, { selected: selected.slice() });';
            $html[] = '  }';
            $html[] = '}';
            $html[] = 'function isPicked(value){ return selected.indexOf(String(value)) > -1; }';
            $html[] = 'function syncHidden(){ hidden.value = selected.join(","); }';
            $html[] = 'function renderTags(){';
            $html[] = '  tags.innerHTML = "";';
            $html[] = '  if (selected.length === 0) { display = document.createElement("span"); display.id = id + "_display"; display.textContent = emptyLabel; tags.appendChild(display); return; }';
            $html[] = '  selected.forEach(function(value){';
            $html[] = '    var item = options.find(function(opt){ return opt.value === value; });';
            $html[] = '    if (!item) return;';
            $html[] = '    if (!isMultiple) { display = document.createElement("span"); display.id = id + "_display"; display.textContent = item.label; tags.appendChild(display); return; }';
            $html[] = '    var tag = document.createElement("span"); tag.className = "weline-registrar-tag";';
            $html[] = '    tag.textContent = item.label;';
            $html[] = '    var rm = document.createElement("span"); rm.className = "weline-registrar-tag-remove"; rm.innerHTML = "&times;";';
            $html[] = '    rm.addEventListener("click", function(e){ e.stopPropagation(); selected = selected.filter(function(v){ return v !== value; }); syncHidden(); renderTags(); renderList(search.value || ""); emitChange(null); });';
            $html[] = '    tag.appendChild(rm); tags.appendChild(tag);';
            $html[] = '  });';
            $html[] = '}';
            $html[] = 'function renderList(keyword){';
            $html[] = '  var kw = String(keyword || "").trim().toLowerCase();';
            $html[] = '  var filtered = options.filter(function(item){ return !kw || item.label.toLowerCase().indexOf(kw) > -1 || item.group.toLowerCase().indexOf(kw) > -1 || item.meta.toLowerCase().indexOf(kw) > -1; });';
            $html[] = '  if (filtered.length === 0) { list.innerHTML = \'<div class="weline-registrar-empty"><?= addslashes((string)__("未找到匹配服务商")) ?></div>\'; return; }';
            $html[] = '  var grouped = {};';
            $html[] = '  filtered.forEach(function(item){ var key = item.group || "default"; if (!grouped[key]) grouped[key] = []; grouped[key].push(item); });';
            $html[] = '  var html = "";';
            $html[] = '  Object.keys(grouped).forEach(function(groupKey){';
            $html[] = '    if (groupKey !== "default") { html += \'<div class="weline-registrar-group">\' + escapeHtml(groupKey) + \'</div>\'; }';
            $html[] = '    grouped[groupKey].forEach(function(item){';
            $html[] = '      var cls = isPicked(item.value) ? " active" : "";';
            $html[] = '      var meta = item.meta ? \'<div class="weline-registrar-item-meta">\' + escapeHtml(item.meta) + \'</div>\' : "";';
            $html[] = '      html += \'<div class="weline-registrar-item\' + cls + \'" data-value="\' + escapeHtml(item.value) + \'"><div>\' + escapeHtml(item.label) + \'</div>\' + meta + \'</div>\';';
            $html[] = '    });';
            $html[] = '  });';
            $html[] = '  list.innerHTML = html;';
            $html[] = '  list.querySelectorAll(".weline-registrar-item").forEach(function(el){';
            $html[] = '    el.addEventListener("click", function(){';
            $html[] = '      var value = this.getAttribute("data-value") || "";';
            $html[] = '      if (!value) return;';
            $html[] = '      var pickedItem = options.find(function(opt){ return opt.value === value; }) || null;';
            $html[] = '      if (isMultiple) {';
            $html[] = '        if (isPicked(value)) { selected = selected.filter(function(v){ return v !== value; }); } else { selected.push(value); }';
            $html[] = '      } else { selected = [value]; closeDropdown(); }';
            $html[] = '      syncHidden(); renderTags(); renderList(search.value || ""); emitChange(pickedItem);';
            $html[] = '    });';
            $html[] = '  });';
            $html[] = '}';
            $html[] = 'function escapeHtml(str){ var div = document.createElement("div"); div.appendChild(document.createTextNode(String(str || ""))); return div.innerHTML; }';
            $html[] = 'function setOptions(nextOptions){ options = normalizeOptions(nextOptions); selected = selected.filter(function(v){ return options.some(function(opt){ return opt.value === v; }); }); if (!isMultiple && selected.length > 1) selected = selected.slice(0,1); syncHidden(); renderTags(); renderList(search.value || ""); }';
            $html[] = 'function setValue(value){';
            $html[] = '  var values = Array.isArray(value) ? value.map(String) : String(value || "").split(",").map(function(v){ return v.trim(); }).filter(Boolean);';
            $html[] = '  selected = isMultiple ? values : (values[0] ? [values[0]] : []);';
            $html[] = '  syncHidden(); renderTags(); renderList(search.value || "");';
            $html[] = '}';
            $html[] = 'function getSelected(){ return selected.map(function(v){ return options.find(function(opt){ return opt.value === v; }) || { value: v, label: v, group: "", meta: "", raw: null }; }); }';
            $html[] = 'function positionDropdown(){ window.WelineSmartDropdown.mount(trigger, dropdown, { minWidth: 280, maxWidth: 350, preferredHeight: 300, zIndex: 4200, gap: 4 }); }';
            $html[] = 'function openDropdown(){';
            $html[] = '  positionDropdown();';
            $html[] = '  floatingOpened = true;';
            $html[] = '  if (search) search.focus();';
            $html[] = '  renderList(search ? (search.value || "") : "");';
            $html[] = '  window.addEventListener("resize", handleViewportChange);';
            $html[] = '  window.addEventListener("scroll", handleViewportChange, true);';
            $html[] = '}';
            $html[] = 'function closeDropdown(){';
            $html[] = '  floatingOpened = false;';
            $html[] = '  window.WelineSmartDropdown.unmount(dropdown);';
            $html[] = '  window.removeEventListener("resize", handleViewportChange);';
            $html[] = '  window.removeEventListener("scroll", handleViewportChange, true);';
            $html[] = '}';
            $html[] = 'function handleViewportChange(){ if (!floatingOpened) return; positionDropdown(); }';
            $html[] = 'trigger.addEventListener("click", function(e){ e.stopPropagation(); if (floatingOpened) { closeDropdown(); } else { openDropdown(); } });';
            $html[] = 'search.addEventListener("input", function(){ renderList(search.value || ""); });';
            $html[] = 'document.addEventListener("click", function(e){ if (!dropdown.contains(e.target) && !trigger.contains(e.target)) { closeDropdown(); } });';
            $html[] = 'options = normalizeOptions(parseOptions(optionsRaw));';
            $html[] = 'selected = readInitialSelection(); if (!isMultiple && selected.length > 1) selected = selected.slice(0,1);';
            $html[] = 'syncHidden(); renderTags(); renderList("");';
            $html[] = 'window.WelineRegistrarSelect = window.WelineRegistrarSelect || {};';
            $html[] = 'window.WelineRegistrarSelect[id] = {';
            $html[] = '  setOptions: setOptions,';
            $html[] = '  setValue: function(value){ setValue(value); emitChange(getSelected()[0] || null); },';
            $html[] = '  getValue: function(){ return String(hidden.value || ""); },';
            $html[] = '  getValues: function(){ return selected.slice(); },';
            $html[] = '  getSelected: getSelected';
            $html[] = '};';
            $html[] = '})();</script>';

            return implode("\n", $html);
        };
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return htmlspecialchars(
            '<h3><code>&lt;w:websites:registrar:select&gt;</code></h3><p>服务商标签选择器，支持分组、搜索、标签化展示。</p>',
            ENT_NOQUOTES
        );
    }
}

