<?php

declare(strict_types=1);

namespace Weline\Websites\Taglib;

/**
 * Website / Store / Channel 等 code 字段共用的可搜索单选渲染器。
 * 由具体 Taglib 包装；id/name/form/on-change 为编译期字面量。
 */
final class SearchableCodeSelect
{
    /**
     * @param array{
     *   id:string,
     *   name:string,
     *   class:string,
     *   style:string,
     *   form:string,
     *   on-change:string,
     *   allow-empty:bool,
     *   clearable:bool,
     *   component:string,
     *   api-ns:string,
     *   default-empty-label:string,
     *   default-placeholder:string,
     *   not-found:string,
     *   attributes:array<string,mixed>
     * } $config
     */
    public static function render(array $config): string
    {
        $attributes = $config['attributes'];
        $idLiteral = (string)$config['id'];
        $nameLiteral = (string)$config['name'];
        $class = (string)$config['class'];
        $style = (string)$config['style'];
        $formAttr = (string)$config['form'];
        $onChange = (string)$config['on-change'];
        $allowEmpty = (bool)$config['allow-empty'];
        $clearable = (bool)$config['clearable'];
        $component = (string)$config['component'];
        $apiNs = (string)$config['api-ns'];
        $defaultEmpty = (string)$config['default-empty-label'];
        $defaultPlaceholder = (string)$config['default-placeholder'];
        $notFound = (string)$config['not-found'];
        $clearTitle = (string)__('清空');

        $attrs = $attributes;
        unset($attrs['id'], $attrs['name'], $attrs['form'], $attrs['class'], $attrs['style'], $attrs['on-change'], $attrs['allow-empty'], $attrs['clearable']);
        $attrs['id'] = $idLiteral;
        $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);

        $html = [];
        $html[] = '<?php ' . $code . ' ?>';
        $html[] = '<?php $__scs_id = ' . \var_export($idLiteral, true)
            . '; $__scs_name = ' . \var_export($nameLiteral, true)
            . '; $__scs_form = ' . \var_export($formAttr, true)
            . '; $__scs_default_empty = ' . \var_export($defaultEmpty, true)
            . '; $__scs_default_placeholder = ' . \var_export($defaultPlaceholder, true)
            . '; ?>';
        $html[] = <<<'PHP'
<?php
$__scs_value = \trim((string)($Taglib__value ?? ''));
$__scs_empty_label = \trim((string)($Taglib__empty_label ?? ''));
if ($__scs_empty_label === '') {
    $__scs_empty_label = (string)$__scs_default_empty;
}
$__scs_placeholder = \trim((string)($Taglib__placeholder ?? ''));
if ($__scs_placeholder === '') {
    $__scs_placeholder = (string)$__scs_default_placeholder;
}
$__scs_options_raw = $Taglib__options ?? null;
$__scs_options = [];
if (\is_string($__scs_options_raw) && $__scs_options_raw !== '') {
    $decoded = \json_decode($__scs_options_raw, true);
    if (\is_array($decoded)) {
        $__scs_options = $decoded;
    }
} elseif (\is_array($__scs_options_raw)) {
    $__scs_options = $__scs_options_raw;
}
$__scs_normalized = [];
foreach ($__scs_options as $__row) {
    if (!\is_array($__row)) {
        continue;
    }
    $__val = (string)($__row['value'] ?? $__row['code'] ?? $__row['id'] ?? '');
    if ($__val === '') {
        continue;
    }
    $__scs_normalized[] = [
        'value' => $__val,
        'label' => (string)($__row['label'] ?? $__row['name'] ?? $__val),
        'meta' => (string)($__row['meta'] ?? $__row['code'] ?? ''),
    ];
}
$__scs_display = '';
foreach ($__scs_normalized as $__row) {
    if (\hash_equals((string)$__row['value'], $__scs_value)) {
        $__scs_display = (string)$__row['label'];
        break;
    }
}
?>
PHP;

        $html[] = '<style>';
        $html[] = '.weline-code-select{position:relative;min-width:160px;max-width:100%;width:100%}';
        $html[] = '.weline-code-select-trigger{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;min-height:42px;padding:8px 12px;background:var(--backend-color-card-bg,#fff);border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:6px;color:var(--backend-color-text-primary,#162033);text-align:left;cursor:pointer}';
        $html[] = '.weline-code-select-trigger:hover,.weline-code-select.is-open .weline-code-select-trigger{border-color:var(--backend-color-primary,#556ee6);box-shadow:0 0 0 3px rgba(85,110,230,.16);outline:0}';
        $html[] = '.weline-code-select-label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.9rem}';
        $html[] = '.weline-code-select-label.is-empty{color:var(--backend-color-text-secondary,#64748b)}';
        $html[] = '.weline-code-select-actions{display:inline-flex;align-items:center;gap:4px;flex:0 0 auto}';
        $html[] = '.weline-code-select-clear{border:0;background:transparent;color:#94a3b8;cursor:pointer;font-size:16px;line-height:1;padding:0}';
        $html[] = '.weline-code-select-clear:hover{color:#ef4444}';
        $html[] = '.weline-code-select-chevron{color:#64748b;font-size:16px;line-height:1}';
        $html[] = '.weline-code-select-dropdown{display:none;padding:8px;background:var(--backend-color-card-bg,#fff);border:1px solid #dbe3ef;border-radius:10px;box-shadow:0 16px 36px rgba(15,23,42,.16);box-sizing:border-box;overflow:hidden}';
        $html[] = '.weline-code-select-search{display:block;width:100%;min-height:36px;padding:6px 10px;margin:0 0 7px;border:1px solid #dbe3ef;border-radius:6px;background:#f8fafc;color:#162033;box-sizing:border-box}';
        $html[] = '.weline-code-select-list{max-height:280px;overflow:auto;overscroll-behavior:contain}';
        $html[] = '.weline-code-select-item{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:8px 10px;border:0;border-radius:7px;background:transparent;color:#162033;text-align:left;cursor:pointer}';
        $html[] = '.weline-code-select-item:hover,.weline-code-select-item.is-selected{background:#f1f5f9;outline:0}';
        $html[] = '.weline-code-select-item-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.85rem}';
        $html[] = '.weline-code-select-item-meta{color:#64748b;font-size:11px;white-space:nowrap}';
        $html[] = '.weline-code-select-empty{padding:10px;text-align:center;color:#64748b;font-size:.85rem}';
        $html[] = '</style>';

        $html[] = '<div class="weline-code-select ' . \htmlspecialchars($class, ENT_QUOTES) . '" style="' . \htmlspecialchars($style, ENT_QUOTES) . '" id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_wrapper" data-component="' . \htmlspecialchars($component, ENT_QUOTES) . '">';
        $html[] = '  <button type="button" class="weline-code-select-trigger" id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_trigger" aria-haspopup="listbox" aria-expanded="false">';
        $html[] = '    <span class="weline-code-select-label<?= $__scs_display === \'\' ? \' is-empty\' : \'\' ?>" id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_display"><?= htmlspecialchars($__scs_display !== \'\' ? $__scs_display : $__scs_empty_label, ENT_QUOTES) ?></span>';
        $html[] = '    <span class="weline-code-select-actions">';
        if ($clearable) {
            $html[] = '      <span class="weline-code-select-clear" id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_clear" title="' . \htmlspecialchars($clearTitle, ENT_QUOTES) . '" hidden>&times;</span>';
        }
        $html[] = '      <span class="weline-code-select-chevron" aria-hidden="true">⌄</span>';
        $html[] = '    </span>';
        $html[] = '  </button>';
        $formAttrHtml = $formAttr !== ''
            ? ' form="<?= htmlspecialchars($__scs_form, ENT_QUOTES) ?>"'
            : '';
        $html[] = '  <input type="hidden" id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>" name="<?= htmlspecialchars($__scs_name, ENT_QUOTES) ?>" value="<?= htmlspecialchars($__scs_value, ENT_QUOTES) ?>"' . $formAttrHtml . ' data-code-select-value>';
        $html[] = '  <div class="weline-code-select-dropdown" id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_dropdown" hidden>';
        $html[] = '    <input type="search" class="weline-code-select-search" id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_search" placeholder="<?= htmlspecialchars($__scs_placeholder, ENT_QUOTES) ?>" autocomplete="off">';
        $html[] = '    <div class="weline-code-select-list" id="<?= htmlspecialchars($__scs_id, ENT_QUOTES) ?>_list" role="listbox"></div>';
        $html[] = '  </div>';
        $html[] = '</div>';

        $html[] = '<script>(function(){';
        $html[] = '"use strict";';
        $html[] = 'var id = <?= json_encode((string)$__scs_id, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
        $html[] = 'var emptyLabel = <?= json_encode((string)$__scs_empty_label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
        $html[] = 'var notFound = ' . \json_encode($notFound, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
        $html[] = 'var allowEmpty = ' . ($allowEmpty ? 'true' : 'false') . ';';
        $html[] = 'var clearable = ' . ($clearable ? 'true' : 'false') . ';';
        $html[] = 'var onChangeCode = ' . \json_encode($onChange, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
        $html[] = 'var apiNs = ' . \json_encode($apiNs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
        $html[] = 'var options = <?= json_encode($__scs_normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
        $html[] = 'var wrapper = document.getElementById(id + "_wrapper");';
        $html[] = 'var trigger = document.getElementById(id + "_trigger");';
        $html[] = 'var dropdown = document.getElementById(id + "_dropdown");';
        $html[] = 'var search = document.getElementById(id + "_search");';
        $html[] = 'var list = document.getElementById(id + "_list");';
        $html[] = 'var display = document.getElementById(id + "_display");';
        $html[] = 'var hidden = document.getElementById(id);';
        $html[] = 'var clearBtn = document.getElementById(id + "_clear");';
        $html[] = 'if (!wrapper || !trigger || !dropdown || !search || !list || !display || !hidden) return;';
        $html[] = 'var selected = String(hidden.value || "");';
        $html[] = 'var open = false;';
        $html[] = 'if (!window.WelineSmartDropdown) {';
        $html[] = '  window.WelineSmartDropdown = (function(){';
        $html[] = '    function compute(anchorRect, panelRect, cfg){';
        $html[] = '      var margin = cfg.margin || 8, gap = cfg.gap || 4;';
        $html[] = '      var vw = window.innerWidth || document.documentElement.clientWidth || 1200;';
        $html[] = '      var vh = window.innerHeight || document.documentElement.clientHeight || 900;';
        $html[] = '      var width = Math.max(cfg.minWidth || 0, panelRect.width || anchorRect.width);';
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
        $html[] = '      return { left:left, top:top, width:width, maxHeight:Math.max(160, vh - margin * 2) };';
        $html[] = '    }';
        $html[] = '    return {';
        $html[] = '      mount:function(anchor,panel,cfg){';
        $html[] = '        cfg = cfg || {}; if (!anchor || !panel) return null;';
        $html[] = '        if (!panel.__welineOriginalParent) { panel.__welineOriginalParent = panel.parentNode || null; panel.__welineOriginalNext = panel.nextSibling || null; }';
        $html[] = '        panel.hidden = false; panel.style.display = "block"; panel.style.position = "fixed"; panel.style.zIndex = String(cfg.zIndex || 4000);';
        $html[] = '        var anchorW = Math.round(anchor.getBoundingClientRect().width);';
        $html[] = '        panel.style.minWidth = Math.max(cfg.minWidth || 0, anchorW) + "px";';
        $html[] = '        panel.style.maxWidth = "calc(100vw - 16px)";';
        $html[] = '        if (panel.parentNode !== document.body) document.body.appendChild(panel);';
        $html[] = '        var rect = anchor.getBoundingClientRect();';
        $html[] = '        var pr = panel.getBoundingClientRect();';
        $html[] = '        var next = compute(rect, pr, cfg);';
        $html[] = '        panel.style.left = Math.round(next.left) + "px";';
        $html[] = '        panel.style.top = Math.round(next.top) + "px";';
        $html[] = '        panel.style.width = Math.round(next.width) + "px";';
        $html[] = '        panel.style.maxHeight = Math.round(next.maxHeight) + "px";';
        $html[] = '        return next;';
        $html[] = '      },';
        $html[] = '      unmount:function(panel){';
        $html[] = '        if (!panel) return; panel.style.display = "none"; panel.hidden = true;';
        $html[] = '        if (panel.__welineOriginalParent) {';
        $html[] = '          if (panel.__welineOriginalNext && panel.__welineOriginalNext.parentNode === panel.__welineOriginalParent) {';
        $html[] = '            panel.__welineOriginalParent.insertBefore(panel, panel.__welineOriginalNext);';
        $html[] = '          } else {';
        $html[] = '            panel.__welineOriginalParent.appendChild(panel);';
        $html[] = '          }';
        $html[] = '        }';
        $html[] = '      }';
        $html[] = '    };';
        $html[] = '  })();';
        $html[] = '}';
        $html[] = 'function labelOf(value){';
        $html[] = '  for (var i=0;i<options.length;i++){ if (String(options[i].value) === String(value)) return options[i].label; }';
        $html[] = '  return "";';
        $html[] = '}';
        $html[] = 'function syncDisplay(){';
        $html[] = '  var label = labelOf(selected);';
        $html[] = '  if (label) { display.textContent = label; display.classList.remove("is-empty"); }';
        $html[] = '  else { display.textContent = emptyLabel; display.classList.add("is-empty"); }';
        $html[] = '  if (clearBtn) clearBtn.hidden = !(clearable && selected);';
        $html[] = '}';
        $html[] = 'function fireChange(){';
        $html[] = '  try {';
        $html[] = '    hidden.dispatchEvent(new Event("input", { bubbles: true }));';
        $html[] = '    hidden.dispatchEvent(new Event("change", { bubbles: true }));';
        $html[] = '  } catch (e) {}';
        $html[] = '  if (!onChangeCode) return;';
        $html[] = '  try { (new Function(onChangeCode))(); } catch (e) { console.error(e); }';
        $html[] = '}';
        $html[] = 'function setValue(value, fire){';
        $html[] = '  selected = String(value || "");';
        $html[] = '  hidden.value = selected;';
        $html[] = '  syncDisplay();';
        $html[] = '  if (fire) fireChange();';
        $html[] = '}';
        $html[] = 'function renderList(keyword){';
        $html[] = '  var q = String(keyword || "").trim().toLowerCase();';
        $html[] = '  list.innerHTML = "";';
        $html[] = '  var matched = options.filter(function(item){';
        $html[] = '    if (!q) return true;';
        $html[] = '    return String(item.value).toLowerCase().indexOf(q) >= 0 || String(item.label).toLowerCase().indexOf(q) >= 0 || String(item.meta || "").toLowerCase().indexOf(q) >= 0;';
        $html[] = '  });';
        $html[] = '  if (allowEmpty && !q) {';
        $html[] = '    var emptyItem = document.createElement("button");';
        $html[] = '    emptyItem.type = "button";';
        $html[] = '    emptyItem.className = "weline-code-select-item" + (selected === "" ? " is-selected" : "");';
        $html[] = '    emptyItem.innerHTML = \'<span class="weline-code-select-item-label"></span>\';';
        $html[] = '    emptyItem.querySelector(".weline-code-select-item-label").textContent = emptyLabel;';
        $html[] = '    emptyItem.addEventListener("click", function(){ setValue("", true); close(); });';
        $html[] = '    list.appendChild(emptyItem);';
        $html[] = '  }';
        $html[] = '  if (!matched.length && !(allowEmpty && !q)) {';
        $html[] = '    var empty = document.createElement("div");';
        $html[] = '    empty.className = "weline-code-select-empty";';
        $html[] = '    empty.textContent = notFound;';
        $html[] = '    list.appendChild(empty);';
        $html[] = '    return;';
        $html[] = '  }';
        $html[] = '  matched.forEach(function(item){';
        $html[] = '    var btn = document.createElement("button");';
        $html[] = '    btn.type = "button";';
        $html[] = '    btn.className = "weline-code-select-item" + (String(item.value) === selected ? " is-selected" : "");';
        $html[] = '    btn.innerHTML = \'<span class="weline-code-select-item-label"></span>\' + (item.meta && item.meta !== item.label ? \'<span class="weline-code-select-item-meta"></span>\' : "");';
        $html[] = '    btn.querySelector(".weline-code-select-item-label").textContent = item.label;';
        $html[] = '    if (item.meta && item.meta !== item.label) btn.querySelector(".weline-code-select-item-meta").textContent = item.meta;';
        $html[] = '    btn.addEventListener("click", function(){ setValue(item.value, true); close(); });';
        $html[] = '    list.appendChild(btn);';
        $html[] = '  });';
        $html[] = '}';
        $html[] = 'function openDropdown(){';
        $html[] = '  if (open) return;';
        $html[] = '  open = true;';
        $html[] = '  wrapper.classList.add("is-open");';
        $html[] = '  trigger.setAttribute("aria-expanded", "true");';
        $html[] = '  renderList(search.value);';
        $html[] = '  window.WelineSmartDropdown.mount(trigger, dropdown, { minWidth: 220, preferredHeight: 320, zIndex: 4200 });';
        $html[] = '  setTimeout(function(){ search.focus(); }, 0);';
        $html[] = '}';
        $html[] = 'function close(){';
        $html[] = '  if (!open) return;';
        $html[] = '  open = false;';
        $html[] = '  wrapper.classList.remove("is-open");';
        $html[] = '  trigger.setAttribute("aria-expanded", "false");';
        $html[] = '  window.WelineSmartDropdown.unmount(dropdown);';
        $html[] = '}';
        $html[] = 'trigger.addEventListener("click", function(e){';
        $html[] = '  e.preventDefault();';
        $html[] = '  if (open) close(); else openDropdown();';
        $html[] = '});';
        $html[] = 'search.addEventListener("input", function(){ renderList(search.value); });';
        $html[] = 'if (clearBtn) {';
        $html[] = '  clearBtn.addEventListener("click", function(e){';
        $html[] = '    e.preventDefault();';
        $html[] = '    e.stopPropagation();';
        $html[] = '    setValue("", true);';
        $html[] = '    close();';
        $html[] = '  });';
        $html[] = '}';
        $html[] = 'document.addEventListener("click", function(e){';
        $html[] = '  if (!open) return;';
        $html[] = '  if (wrapper.contains(e.target) || dropdown.contains(e.target)) return;';
        $html[] = '  close();';
        $html[] = '});';
        $html[] = 'window.addEventListener("resize", function(){ if (open) window.WelineSmartDropdown.mount(trigger, dropdown, { minWidth: 220, preferredHeight: 320, zIndex: 4200 }); });';
        $html[] = 'window.addEventListener("scroll", function(){ if (open) close(); }, true);';
        $html[] = 'window[apiNs] = window[apiNs] || {};';
        $html[] = 'window[apiNs][id] = { setValue: function(v){ setValue(v, true); }, getValue: function(){ return String(hidden.value || ""); }, setOptions: function(next){ options = Array.isArray(next) ? next : []; selected = options.some(function(o){ return String(o.value) === selected; }) ? selected : ""; hidden.value = selected; syncDisplay(); if (open) renderList(search.value); } };';
        $html[] = 'syncDisplay();';
        $html[] = '})();</script>';

        return \implode("\n", $html);
    }
}
