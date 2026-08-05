<?php

declare(strict_types=1);

namespace Weline\Acl\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * ACL 标签多选（可搜索）。
 *
 * 使用示例：
 * <?php
 * $acl_tag_filter_value = 'query,media';
 * $acl_tag_filter_empty = __('所有标签');
 * $acl_tag_filter_placeholder = __('搜索标签');
 * $acl_tag_filter_options_json = json_encode($options, JSON_UNESCAPED_UNICODE);
 * ?>
 * <w:acl:tag:select
 *     id="acl-filter-tags"
 *     name="tags"
 *     value="acl_tag_filter_value"
 *     options="acl_tag_filter_options_json"
 *     empty-label="acl_tag_filter_empty"
 *     placeholder="acl_tag_filter_placeholder"
 *     form="acl-list-filter-form"
 *     on-change="document.getElementById('acl-list-filter-form').requestSubmit()"
 * />
 *
 * id/name/form/class/style/on-change 为编译期字面量；
 * value/options/empty-label/placeholder 按变量名解析。
 * 隐藏域值为逗号分隔标签词；未传 options 时自动扫描 ACL 资源标签。
 */
class TagSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'acl:tag:select';
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
            'form' => false,
            'on-change' => false,
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
            $onChange = (string)($attributes['on-change'] ?? '');
            $formAttr = (string)($attributes['form'] ?? '');
            $idLiteral = (string)$attributes['id'];
            $nameLiteral = (string)($attributes['name'] ?? 'tags');
            $notFound = (string)__('未找到匹配标签');
            $removeTitle = (string)__('移除');

            $attrs = $attributes;
            unset($attrs['id'], $attrs['name'], $attrs['form'], $attrs['class'], $attrs['style'], $attrs['on-change']);
            $attrs['id'] = $idLiteral;
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';
            $html[] = '<?php $__ats_id = ' . \var_export($idLiteral, true) . '; $__ats_name = ' . \var_export($nameLiteral, true) . '; $__ats_form = ' . \var_export($formAttr, true) . '; ?>';
            $html[] = <<<'PHP'
<?php
$__ats_value = \trim((string)($Taglib__value ?? ''));
$__ats_empty_label = \trim((string)($Taglib__empty_label ?? ''));
if ($__ats_empty_label === '') {
    $__ats_empty_label = (string)__('所有标签');
}
$__ats_placeholder = \trim((string)($Taglib__placeholder ?? ''));
if ($__ats_placeholder === '') {
    $__ats_placeholder = (string)__('搜索标签');
}
$__ats_options_raw = $Taglib__options ?? null;
$__ats_options = [];
if (\is_string($__ats_options_raw) && $__ats_options_raw !== '') {
    $decoded = \json_decode($__ats_options_raw, true);
    if (\is_array($decoded)) {
        $__ats_options = $decoded;
    }
} elseif (\is_array($__ats_options_raw)) {
    $__ats_options = $__ats_options_raw;
}
if ($__ats_options === []) {
    $__aclRows = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Acl\Model\Acl::class)
        ->reset()
        ->fields([
            \Weline\Acl\Model\Acl::schema_fields_SOURCE_ID,
            \Weline\Acl\Model\Acl::schema_fields_RESOURCE_METADATA,
        ])
        ->select()
        ->fetchArray();
    $__metaRows = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Acl\Model\AclTag::class)
        ->reset()
        ->select()
        ->fetchArray();
    $__metaByTag = [];
    foreach ($__metaRows as $__meta) {
        $__metaByTag[(string)($__meta[\Weline\Acl\Model\AclTag::schema_fields_TAG] ?? '')] = $__meta;
    }
    $__ats_options = \Weline\Acl\Service\Resource\AclResourcePresentation::buildTagSelectOptions($__aclRows, $__metaByTag);
}
$__ats_normalized = [];
foreach ($__ats_options as $__row) {
    if (!\is_array($__row)) {
        continue;
    }
    $__val = (string)($__row['value'] ?? $__row['tag'] ?? '');
    if ($__val === '') {
        continue;
    }
    $__ats_normalized[] = [
        'value' => $__val,
        'label' => (string)($__row['label'] ?? $__row['display_name'] ?? $__val),
        'meta' => (string)($__row['meta'] ?? $__row['resource_count'] ?? ''),
    ];
}
?>
PHP;

            $html[] = '<style>';
            $html[] = '.weline-acl-tag-select{position:relative;min-width:240px;max-width:100%}';
            $html[] = '.weline-acl-tag-select-trigger{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;min-height:42px;padding:6px 12px;background:var(--backend-color-card-bg,#fff);border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:6px;color:var(--backend-color-text-primary,#162033);text-align:left;cursor:pointer}';
            $html[] = '.weline-acl-tag-select-trigger:hover,.weline-acl-tag-select.is-open .weline-acl-tag-select-trigger{border-color:var(--backend-color-primary,#556ee6);box-shadow:0 0 0 3px rgba(85,110,230,.16);outline:0}';
            $html[] = '.weline-acl-tag-select-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;flex:1;min-width:0;max-height:72px;overflow:auto}';
            $html[] = '.weline-acl-tag-select-empty{color:var(--backend-color-text-secondary,#64748b);font-size:.9rem}';
            $html[] = '.weline-acl-tag-select-chip{display:inline-flex;align-items:center;gap:4px;max-width:100%;padding:2px 8px;border-radius:999px;background:rgba(85,110,230,.1);color:#556ee6;font-size:12px;line-height:1.4;white-space:nowrap}';
            $html[] = '.weline-acl-tag-select-chip-label{min-width:0;overflow:hidden;text-overflow:ellipsis;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}';
            $html[] = '.weline-acl-tag-select-chip-remove{cursor:pointer;opacity:.75;font-size:14px;line-height:1;border:0;background:transparent;color:inherit;padding:0}';
            $html[] = '.weline-acl-tag-select-chip-remove:hover{opacity:1;color:#ef4444}';
            $html[] = '.weline-acl-tag-select-chevron{color:#64748b;font-size:16px;line-height:1;flex:0 0 auto}';
            $html[] = '.weline-acl-tag-select-dropdown{display:none;padding:8px;background:var(--backend-color-card-bg,#fff);border:1px solid #dbe3ef;border-radius:10px;box-shadow:0 16px 36px rgba(15,23,42,.16);box-sizing:border-box;overflow:hidden}';
            $html[] = '.weline-acl-tag-select-search{display:block;width:100%;min-height:36px;padding:6px 10px;margin:0 0 7px;border:1px solid #dbe3ef;border-radius:6px;background:#f8fafc;color:#162033;box-sizing:border-box}';
            $html[] = '.weline-acl-tag-select-list{max-height:280px;overflow:auto;overscroll-behavior:contain}';
            $html[] = '.weline-acl-tag-select-item{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:8px 10px;border:0;border-radius:7px;background:transparent;color:#162033;text-align:left;cursor:pointer}';
            $html[] = '.weline-acl-tag-select-item:hover,.weline-acl-tag-select-item.is-selected{background:#f1f5f9;outline:0}';
            $html[] = '.weline-acl-tag-select-item-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.82rem}';
            $html[] = '.weline-acl-tag-select-item-meta{color:#64748b;font-size:11px;white-space:nowrap}';
            $html[] = '.weline-acl-tag-select-check{color:#556ee6;font-weight:700;opacity:0}';
            $html[] = '.weline-acl-tag-select-item.is-selected .weline-acl-tag-select-check{opacity:1}';
            $html[] = '.weline-acl-tag-select-empty-state{padding:10px;text-align:center;color:#64748b;font-size:.85rem}';
            $html[] = '</style>';

            $formAttrHtml = $formAttr !== ''
                ? ' form="<?= htmlspecialchars($__ats_form, ENT_QUOTES) ?>"'
                : '';

            $html[] = '<div class="weline-acl-tag-select ' . \htmlspecialchars($class, ENT_QUOTES) . '" style="' . \htmlspecialchars($style, ENT_QUOTES) . '" id="<?= htmlspecialchars($__ats_id, ENT_QUOTES) ?>_wrapper" data-component="acl-tag-select">';
            $html[] = '  <button type="button" class="weline-acl-tag-select-trigger" id="<?= htmlspecialchars($__ats_id, ENT_QUOTES) ?>_trigger" aria-haspopup="listbox" aria-expanded="false">';
            $html[] = '    <div class="weline-acl-tag-select-chips" id="<?= htmlspecialchars($__ats_id, ENT_QUOTES) ?>_chips"><span class="weline-acl-tag-select-empty"><?= htmlspecialchars($__ats_empty_label, ENT_QUOTES) ?></span></div>';
            $html[] = '    <span class="weline-acl-tag-select-chevron" aria-hidden="true">⌄</span>';
            $html[] = '  </button>';
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($__ats_id, ENT_QUOTES) ?>" name="<?= htmlspecialchars($__ats_name, ENT_QUOTES) ?>" value="<?= htmlspecialchars($__ats_value, ENT_QUOTES) ?>"' . $formAttrHtml . ' data-acl-tag-select-value>';
            $html[] = '  <div class="weline-acl-tag-select-dropdown" id="<?= htmlspecialchars($__ats_id, ENT_QUOTES) ?>_dropdown" hidden>';
            $html[] = '    <input type="search" class="weline-acl-tag-select-search" id="<?= htmlspecialchars($__ats_id, ENT_QUOTES) ?>_search" placeholder="<?= htmlspecialchars($__ats_placeholder, ENT_QUOTES) ?>" autocomplete="off">';
            $html[] = '    <div class="weline-acl-tag-select-list" id="<?= htmlspecialchars($__ats_id, ENT_QUOTES) ?>_list" role="listbox"></div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            $html[] = \Weline\Framework\View\Taglib\Support\FloatingDropdownEmitter::script();
            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var id = <?= json_encode((string)$__ats_id, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var emptyLabel = <?= json_encode((string)$__ats_empty_label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var notFound = ' . \json_encode($notFound, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var removeTitle = ' . \json_encode($removeTitle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var onChangeCode = ' . \json_encode($onChange, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var options = <?= json_encode($__ats_normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var wrapper = document.getElementById(id + "_wrapper");';
            $html[] = 'var trigger = document.getElementById(id + "_trigger");';
            $html[] = 'var dropdown = document.getElementById(id + "_dropdown");';
            $html[] = 'var search = document.getElementById(id + "_search");';
            $html[] = 'var list = document.getElementById(id + "_list");';
            $html[] = 'var chips = document.getElementById(id + "_chips");';
            $html[] = 'var hidden = document.getElementById(id);';
            $html[] = 'if (!wrapper || !trigger || !dropdown || !search || !list || !chips || !hidden) return;';
            $html[] = 'var selected = String(hidden.value || "").split(",").map(function(v){ return String(v || "").trim(); }).filter(Boolean);';
            $html[] = 'var open = false;';
            $html[] = 'var baseline = String(hidden.value || "");';
            $html[] = 'var dirty = false;';
            $html[] = 'function isPicked(value){ return selected.indexOf(String(value)) >= 0; }';
            $html[] = 'function syncHidden(){ hidden.value = selected.join(","); }';
            $html[] = 'function fireChange(){';
            $html[] = '  if (!onChangeCode) return;';
            $html[] = '  try { (new Function(onChangeCode))(); } catch (e) { console.error(e); }';
            $html[] = '}';
            $html[] = 'function commitIfChanged(){';
            $html[] = '  syncHidden();';
            $html[] = '  var next = String(hidden.value || "");';
            $html[] = '  if (next === baseline) { dirty = false; return; }';
            $html[] = '  baseline = next;';
            $html[] = '  dirty = false;';
            $html[] = '  fireChange();';
            $html[] = '}';
            $html[] = 'function renderChips(){';
            $html[] = '  chips.innerHTML = "";';
            $html[] = '  if (!selected.length) {';
            $html[] = '    var empty = document.createElement("span");';
            $html[] = '    empty.className = "weline-acl-tag-select-empty";';
            $html[] = '    empty.textContent = emptyLabel;';
            $html[] = '    chips.appendChild(empty);';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  selected.forEach(function(value){';
            $html[] = '    var opt = options.find(function(item){ return String(item.value) === String(value); });';
            $html[] = '    var chip = document.createElement("span");';
            $html[] = '    chip.className = "weline-acl-tag-select-chip";';
            $html[] = '    var label = document.createElement("span");';
            $html[] = '    label.className = "weline-acl-tag-select-chip-label";';
            $html[] = '    label.textContent = opt ? opt.label : value;';
            $html[] = '    chip.appendChild(label);';
            $html[] = '    var rm = document.createElement("button");';
            $html[] = '    rm.type = "button";';
            $html[] = '    rm.className = "weline-acl-tag-select-chip-remove";';
            $html[] = '    rm.title = removeTitle;';
            $html[] = '    rm.innerHTML = "&times;";';
            $html[] = '    rm.addEventListener("click", function(e){';
            $html[] = '      e.preventDefault(); e.stopPropagation();';
            $html[] = '      selected = selected.filter(function(v){ return String(v) !== String(value); });';
            $html[] = '      dirty = true; syncHidden(); renderChips(); renderList(search.value);';
            $html[] = '      if (!open) commitIfChanged();';
            $html[] = '    });';
            $html[] = '    chip.appendChild(rm);';
            $html[] = '    chips.appendChild(chip);';
            $html[] = '  });';
            $html[] = '}';
            $html[] = 'function renderList(keyword){';
            $html[] = '  var q = String(keyword || "").trim().toLowerCase();';
            $html[] = '  list.innerHTML = "";';
            $html[] = '  var matched = options.filter(function(item){';
            $html[] = '    if (!q) return true;';
            $html[] = '    return String(item.value).toLowerCase().indexOf(q) >= 0 || String(item.label).toLowerCase().indexOf(q) >= 0;';
            $html[] = '  });';
            $html[] = '  if (!matched.length) {';
            $html[] = '    var empty = document.createElement("div");';
            $html[] = '    empty.className = "weline-acl-tag-select-empty-state";';
            $html[] = '    empty.textContent = notFound;';
            $html[] = '    list.appendChild(empty);';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  matched.forEach(function(item){';
            $html[] = '    var btn = document.createElement("button");';
            $html[] = '    btn.type = "button";';
            $html[] = '    btn.className = "weline-acl-tag-select-item" + (isPicked(item.value) ? " is-selected" : "");';
            $html[] = '    btn.innerHTML = \'<span class="weline-acl-tag-select-item-label"></span>\' + (item.meta ? \'<span class="weline-acl-tag-select-item-meta"></span>\' : "") + \'<span class="weline-acl-tag-select-check" aria-hidden="true">✓</span>\';';
            $html[] = '    btn.querySelector(".weline-acl-tag-select-item-label").textContent = item.label;';
            $html[] = '    if (item.meta) btn.querySelector(".weline-acl-tag-select-item-meta").textContent = item.meta;';
            $html[] = '    btn.addEventListener("click", function(){';
            $html[] = '      if (isPicked(item.value)) {';
            $html[] = '        selected = selected.filter(function(v){ return String(v) !== String(item.value); });';
            $html[] = '      } else {';
            $html[] = '        selected.push(String(item.value));';
            $html[] = '      }';
            $html[] = '      dirty = true; syncHidden(); renderChips(); renderList(search.value);';
            $html[] = '    });';
            $html[] = '    list.appendChild(btn);';
            $html[] = '  });';
            $html[] = '}';
            $html[] = 'function openDropdown(){';
            $html[] = '  if (open) return;';
            $html[] = '  open = true;';
            $html[] = '  wrapper.classList.add("is-open");';
            $html[] = '  trigger.setAttribute("aria-expanded", "true");';
            $html[] = '  renderList(search.value);';
            $html[] = '  window.WelineTaglibFloatingDropdown.mount(trigger, dropdown, { minWidth: 260, preferredHeight: 320, zIndex: 4200 });';
            $html[] = '  setTimeout(function(){ search.focus(); }, 0);';
            $html[] = '}';
            $html[] = 'function close(){';
            $html[] = '  if (!open) return;';
            $html[] = '  open = false;';
            $html[] = '  wrapper.classList.remove("is-open");';
            $html[] = '  trigger.setAttribute("aria-expanded", "false");';
            $html[] = '  window.WelineTaglibFloatingDropdown.unmount(dropdown);';
            $html[] = '  if (dirty) commitIfChanged();';
            $html[] = '}';
            $html[] = 'trigger.addEventListener("click", function(e){';
            $html[] = '  e.preventDefault();';
            $html[] = '  if (open) close(); else openDropdown();';
            $html[] = '});';
            $html[] = 'search.addEventListener("input", function(){ renderList(search.value); });';
            $html[] = 'document.addEventListener("click", function(e){';
            $html[] = '  if (!open) return;';
            $html[] = '  if (wrapper.contains(e.target) || dropdown.contains(e.target)) return;';
            $html[] = '  close();';
            $html[] = '});';
            $html[] = 'window.addEventListener("resize", function(){ if (open) window.WelineTaglibFloatingDropdown.mount(trigger, dropdown, { minWidth: 260, preferredHeight: 320, zIndex: 4200 }); });';
            $html[] = 'window.addEventListener("scroll", function(){ if (open) close(); }, true);';
            $html[] = 'window.WelineAclTagSelect = window.WelineAclTagSelect || {};';
            $html[] = 'window.WelineAclTagSelect[id] = {';
            $html[] = '  getValue: function(){ return String(hidden.value || ""); },';
            $html[] = '  getValues: function(){ return selected.slice(); },';
            $html[] = '  setValue: function(v){';
            $html[] = '    selected = String(v || "").split(",").map(function(x){ return String(x || "").trim(); }).filter(Boolean);';
            $html[] = '    dirty = true; syncHidden(); renderChips(); renderList(search.value);';
            $html[] = '    if (!open) commitIfChanged();';
            $html[] = '  }';
            $html[] = '};';
            $html[] = 'syncHidden(); renderChips();';
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
            '<h3><code>&lt;w:acl:tag:select&gt;</code></h3><p>ACL 标签多选，隐藏域逗号分隔；可选 form / on-change。</p>',
            ENT_NOQUOTES
        );
    }
}
