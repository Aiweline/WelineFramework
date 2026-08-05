<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 模块选择标签（可搜索单选）。
 *
 * 使用示例：
 * <?php $moduleEmpty = __('所有模块'); $modulePlaceholder = __('搜索模块名称'); ?>
 * <w:module-manager:module:select
 *     id="acl-filter-module"
 *     name="module"
 *     value="current_module"
 *     allow-empty="true"
 *     empty-label="moduleEmpty"
 *     placeholder="modulePlaceholder"
 *     form="acl-list-filter-form"
 *     on-change="document.getElementById('acl-list-filter-form').requestSubmit()"
 * />
 *
 * id/name/form/class/style/on-change 为编译期字面量（避免 name="module" 撞上后台 $module）。
 * value/options/empty-label/placeholder 按变量名解析（Weline_Taglib_resolve）。
 *
 * 可选 attributes.options 传入 PHP 变量名（JSON 数组 [{value,label}]）；
 * 未传时自动读取已启用模块列表。
 */
class ModuleSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'module-manager:module:select';
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
            'options' => false,
            'class' => false,
            'style' => false,
            'placeholder' => false,
            'empty-label' => false,
            'allow-empty' => false,
            'form' => false,
            'on-change' => false,
            'clearable' => false,
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }

            $class = (string)($attributes['class'] ?? '');
            $style = (string)($attributes['style'] ?? '');
            $allowEmptyRaw = (string)($attributes['allow-empty'] ?? 'true');
            $allowEmpty = \in_array(\strtolower(\trim($allowEmptyRaw)), ['true', '1', 'yes', ''], true);
            $clearableRaw = (string)($attributes['clearable'] ?? ($allowEmpty ? 'true' : 'false'));
            $clearable = \in_array(\strtolower(\trim($clearableRaw)), ['true', '1', 'yes'], true);
            $onChange = (string)($attributes['on-change'] ?? '');
            $formAttr = (string)($attributes['form'] ?? '');
            $idLiteral = (string)$attributes['id'];
            $nameLiteral = (string)($attributes['name'] ?? 'module');
            $notFound = (string)__('未找到匹配模块');
            $clearTitle = (string)__('清空');

            // value/options/empty-label/placeholder 走变量解析；id/name/form 用编译期字面量，
            // 避免后台模板 $module 把 name="module" 解析成当前模块名（如 Weline_Backend）。
            $attrs = $attributes;
            unset($attrs['id'], $attrs['name'], $attrs['form'], $attrs['class'], $attrs['style'], $attrs['on-change'], $attrs['allow-empty'], $attrs['clearable']);
            $attrs['id'] = $idLiteral; // AttributeCodeCompiler 需要 id
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';
            $html[] = '<?php $__mms_id = ' . \var_export($idLiteral, true) . '; $__mms_name = ' . \var_export($nameLiteral, true) . '; $__mms_form = ' . \var_export($formAttr, true) . '; ?>';
            $html[] = <<<'PHP'
<?php
$__mms_value = \trim((string)($Taglib__value ?? ''));
$__mms_empty_label = \trim((string)($Taglib__empty_label ?? ''));
if ($__mms_empty_label === '') {
    $__mms_empty_label = (string)__('所有模块');
}
$__mms_placeholder = \trim((string)($Taglib__placeholder ?? ''));
if ($__mms_placeholder === '') {
    $__mms_placeholder = (string)__('搜索模块名称');
}
$__mms_options_raw = $Taglib__options ?? null;
$__mms_options = [];
if (\is_string($__mms_options_raw) && $__mms_options_raw !== '') {
    $decoded = \json_decode($__mms_options_raw, true);
    if (\is_array($decoded)) {
        $__mms_options = $decoded;
    }
} elseif (\is_array($__mms_options_raw)) {
    $__mms_options = $__mms_options_raw;
}
if ($__mms_options === []) {
    foreach (\Weline\Framework\App\Env::getInstance()->getActiveModules() as $__mod) {
        if (!\is_array($__mod)) {
            continue;
        }
        $__name = (string)($__mod['name'] ?? '');
        if ($__name === '') {
            continue;
        }
        $__mms_options[] = [
            'value' => $__name,
            'label' => $__name,
            'meta' => (string)($__mod['version'] ?? ''),
        ];
    }
    \usort($__mms_options, static fn($a, $b) => \strcmp((string)$a['value'], (string)$b['value']));
}
$__mms_normalized = [];
foreach ($__mms_options as $__row) {
    if (!\is_array($__row)) {
        continue;
    }
    $__val = (string)($__row['value'] ?? $__row['name'] ?? $__row['id'] ?? '');
    if ($__val === '') {
        continue;
    }
    $__mms_normalized[] = [
        'value' => $__val,
        'label' => (string)($__row['label'] ?? $__row['name'] ?? $__val),
        'meta' => (string)($__row['meta'] ?? $__row['version'] ?? ''),
    ];
}
$__mms_display = '';
foreach ($__mms_normalized as $__row) {
    if (\hash_equals((string)$__row['value'], $__mms_value)) {
        $__mms_display = (string)$__row['label'];
        break;
    }
}
?>
PHP;

            $html[] = '<style>';
            $html[] = '.weline-module-select{position:relative;min-width:220px;max-width:100%}';
            $html[] = '.weline-module-select-trigger{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;min-height:42px;padding:8px 12px;background:var(--backend-color-card-bg,#fff);border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:6px;color:var(--backend-color-text-primary,#162033);text-align:left;cursor:pointer}';
            $html[] = '.weline-module-select-trigger:hover,.weline-module-select.is-open .weline-module-select-trigger{border-color:var(--backend-color-primary,#556ee6);box-shadow:0 0 0 3px rgba(85,110,230,.16);outline:0}';
            $html[] = '.weline-module-select-label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.9rem}';
            $html[] = '.weline-module-select-label.is-empty{color:var(--backend-color-text-secondary,#64748b)}';
            $html[] = '.weline-module-select-actions{display:inline-flex;align-items:center;gap:4px;flex:0 0 auto}';
            $html[] = '.weline-module-select-clear{border:0;background:transparent;color:#94a3b8;cursor:pointer;font-size:16px;line-height:1;padding:0}';
            $html[] = '.weline-module-select-clear:hover{color:#ef4444}';
            $html[] = '.weline-module-select-chevron{color:#64748b;font-size:16px;line-height:1}';
            $html[] = '.weline-module-select-dropdown{display:none;padding:8px;background:var(--backend-color-card-bg,#fff);border:1px solid #dbe3ef;border-radius:10px;box-shadow:0 16px 36px rgba(15,23,42,.16);box-sizing:border-box;overflow:hidden}';
            $html[] = '.weline-module-select-search{display:block;width:100%;min-height:36px;padding:6px 10px;margin:0 0 7px;border:1px solid #dbe3ef;border-radius:6px;background:#f8fafc;color:#162033;box-sizing:border-box}';
            $html[] = '.weline-module-select-list{max-height:280px;overflow:auto;overscroll-behavior:contain}';
            $html[] = '.weline-module-select-item{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:8px 10px;border:0;border-radius:7px;background:transparent;color:#162033;text-align:left;cursor:pointer}';
            $html[] = '.weline-module-select-item:hover,.weline-module-select-item.is-selected{background:#f1f5f9;outline:0}';
            $html[] = '.weline-module-select-item-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.82rem}';
            $html[] = '.weline-module-select-item-meta{color:#64748b;font-size:11px;white-space:nowrap}';
            $html[] = '.weline-module-select-empty{padding:10px;text-align:center;color:#64748b;font-size:.85rem}';
            $html[] = '</style>';

            $html[] = '<div class="weline-module-select ' . \htmlspecialchars($class, ENT_QUOTES) . '" style="' . \htmlspecialchars($style, ENT_QUOTES) . '" id="<?= htmlspecialchars($__mms_id, ENT_QUOTES) ?>_wrapper" data-component="module-select">';
            $html[] = '  <button type="button" class="weline-module-select-trigger" id="<?= htmlspecialchars($__mms_id, ENT_QUOTES) ?>_trigger" aria-haspopup="listbox" aria-expanded="false">';
            $html[] = '    <span class="weline-module-select-label<?= $__mms_display === \'\' ? \' is-empty\' : \'\' ?>" id="<?= htmlspecialchars($__mms_id, ENT_QUOTES) ?>_display"><?= htmlspecialchars($__mms_display !== \'\' ? $__mms_display : $__mms_empty_label, ENT_QUOTES) ?></span>';
            $html[] = '    <span class="weline-module-select-actions">';
            if ($clearable) {
                $html[] = '      <span class="weline-module-select-clear" id="<?= htmlspecialchars($__mms_id, ENT_QUOTES) ?>_clear" title="' . \htmlspecialchars($clearTitle, ENT_QUOTES) . '" hidden>&times;</span>';
            }
            $html[] = '      <span class="weline-module-select-chevron" aria-hidden="true">⌄</span>';
            $html[] = '    </span>';
            $html[] = '  </button>';
            $formAttrHtml = $formAttr !== ''
                ? ' form="<?= htmlspecialchars($__mms_form, ENT_QUOTES) ?>"'
                : '';
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($__mms_id, ENT_QUOTES) ?>" name="<?= htmlspecialchars($__mms_name, ENT_QUOTES) ?>" value="<?= htmlspecialchars($__mms_value, ENT_QUOTES) ?>"' . $formAttrHtml . ' data-module-select-value>';
            $html[] = '  <div class="weline-module-select-dropdown" id="<?= htmlspecialchars($__mms_id, ENT_QUOTES) ?>_dropdown" hidden>';
            $html[] = '    <input type="search" class="weline-module-select-search" id="<?= htmlspecialchars($__mms_id, ENT_QUOTES) ?>_search" placeholder="<?= htmlspecialchars($__mms_placeholder, ENT_QUOTES) ?>" autocomplete="off">';
            $html[] = '    <div class="weline-module-select-list" id="<?= htmlspecialchars($__mms_id, ENT_QUOTES) ?>_list" role="listbox"></div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var id = <?= json_encode((string)$__mms_id, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var emptyLabel = <?= json_encode((string)$__mms_empty_label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var notFound = ' . \json_encode($notFound, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var allowEmpty = ' . ($allowEmpty ? 'true' : 'false') . ';';
            $html[] = 'var clearable = ' . ($clearable ? 'true' : 'false') . ';';
            $html[] = 'var onChangeCode = ' . \json_encode($onChange, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var options = <?= json_encode($__mms_normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
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
            $html[] = '    return String(item.value).toLowerCase().indexOf(q) >= 0 || String(item.label).toLowerCase().indexOf(q) >= 0;';
            $html[] = '  });';
            $html[] = '  if (allowEmpty && !q) {';
            $html[] = '    var emptyItem = document.createElement("button");';
            $html[] = '    emptyItem.type = "button";';
            $html[] = '    emptyItem.className = "weline-module-select-item" + (selected === "" ? " is-selected" : "");';
            $html[] = '    emptyItem.innerHTML = \'<span class="weline-module-select-item-label"></span>\';';
            $html[] = '    emptyItem.querySelector(".weline-module-select-item-label").textContent = emptyLabel;';
            $html[] = '    emptyItem.addEventListener("click", function(){ setValue("", true); close(); });';
            $html[] = '    list.appendChild(emptyItem);';
            $html[] = '  }';
            $html[] = '  if (!matched.length) {';
            $html[] = '    var empty = document.createElement("div");';
            $html[] = '    empty.className = "weline-module-select-empty";';
            $html[] = '    empty.textContent = notFound;';
            $html[] = '    list.appendChild(empty);';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  matched.forEach(function(item){';
            $html[] = '    var btn = document.createElement("button");';
            $html[] = '    btn.type = "button";';
            $html[] = '    btn.className = "weline-module-select-item" + (String(item.value) === selected ? " is-selected" : "");';
            $html[] = '    btn.innerHTML = \'<span class="weline-module-select-item-label"></span>\' + (item.meta ? \'<span class="weline-module-select-item-meta"></span>\' : "");';
            $html[] = '    btn.querySelector(".weline-module-select-item-label").textContent = item.label;';
            $html[] = '    if (item.meta) btn.querySelector(".weline-module-select-item-meta").textContent = item.meta;';
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
            $html[] = '  window.WelineSmartDropdown.mount(trigger, dropdown, { minWidth: 240, preferredHeight: 320, zIndex: 4200 });';
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
            $html[] = 'window.addEventListener("resize", function(){ if (open) window.WelineSmartDropdown.mount(trigger, dropdown, { minWidth: 240, preferredHeight: 320, zIndex: 4200 }); });';
            $html[] = 'window.addEventListener("scroll", function(){ if (open) close(); }, true);';
            $html[] = 'window.WelineModuleSelect = window.WelineModuleSelect || {};';
            $html[] = 'window.WelineModuleSelect[id] = { setValue: function(v){ setValue(v, true); }, getValue: function(){ return String(hidden.value || ""); } };';
            $html[] = 'syncDisplay();';
            $html[] = '})();</script>';

            return \implode("\n", $html);
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
        return \htmlspecialchars(
            '<h3><code>&lt;w:module-manager:module:select&gt;</code></h3><p>模块选择标签，支持搜索、可空「所有模块」、form 关联与 on-change。</p>',
            ENT_NOQUOTES
        );
    }
}
