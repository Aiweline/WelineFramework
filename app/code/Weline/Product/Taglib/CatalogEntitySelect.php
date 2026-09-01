<?php

declare(strict_types=1);

namespace Weline\Product\Taglib;

use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Taglib\Support\FloatingDropdownEmitter;

/**
 * 商品目录实体选择（品牌/供应商）：芯片展示 + 搜索勾选 + 叉叉移除 + 回填。
 *
 * <w:product:catalog:select
 *     id="product-brand-suppliers"
 *     name="supplier_ids"
 *     entity="supplier"
 *     multiple="true"
 *     value="selectedSupplierIdsCsv"
 *     options="supplierOptionsJson"
 *     empty-label="emptyLabel"
 *     placeholder="searchPlaceholder"
 * />
 *
 * options 项：value|brand_id|supplier_id、label|name、meta|code、image|image_url|logo_url。
 * 隐藏域值为逗号分隔 ID（多选）或单个 ID（单选）。
 * JS API：window.WelineProductCatalogSelect[id]
 *   getValue()/getValues()/setValue()/setOptions()/setAllowedValues()/clear()
 */
final class CatalogEntitySelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'product:catalog:select';
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
            'entity' => false,
            'value' => false,
            'options' => false,
            'multiple' => false,
            'allow-empty' => false,
            'class' => false,
            'style' => false,
            'placeholder' => false,
            'empty-label' => false,
            'form' => false,
            'on-change' => false,
            'scope' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception((string)\__('id属性不能为空'));
            }

            $class = (string)($attributes['class'] ?? '');
            $style = (string)($attributes['style'] ?? '');
            $onChange = (string)($attributes['on-change'] ?? '');
            $formAttr = (string)($attributes['form'] ?? '');
            $scopeAttr = (string)($attributes['scope'] ?? '');
            $entityLiteral = strtolower(trim((string)($attributes['entity'] ?? 'entity')));
            if (!in_array($entityLiteral, ['brand', 'supplier', 'entity'], true)) {
                $entityLiteral = 'entity';
            }
            $multipleRaw = (string)($attributes['multiple'] ?? 'false');
            $isMultiple = in_array(strtolower(trim($multipleRaw)), ['true', '1', 'yes'], true);
            $allowEmptyRaw = (string)($attributes['allow-empty'] ?? 'true');
            $allowEmpty = in_array(strtolower(trim($allowEmptyRaw)), ['true', '1', 'yes', ''], true);
            $idLiteral = (string)$attributes['id'];
            $nameLiteral = (string)($attributes['name'] ?? ($entityLiteral . '_ids'));
            $notFound = (string)\__('未找到匹配项');
            $removeTitle = (string)\__('移除');

            $attrs = $attributes;
            unset(
                $attrs['id'],
                $attrs['name'],
                $attrs['entity'],
                $attrs['class'],
                $attrs['style'],
                $attrs['on-change'],
                $attrs['form'],
                $attrs['scope'],
                $attrs['multiple'],
                $attrs['allow-empty'],
            );
            $code = AttributeCodeCompiler::attributes($attrs);
            $code .= "\n\$__pcs_id = " . var_export($idLiteral, true) . ';';
            $code .= "\n\$__pcs_name = " . var_export($nameLiteral, true) . ';';
            $code .= "\n\$__pcs_entity = " . var_export($entityLiteral, true) . ';';
            $code .= "\n\$__pcs_multiple = " . ($isMultiple ? 'true' : 'false') . ';';
            $code .= "\n\$__pcs_allow_empty = " . ($allowEmpty ? 'true' : 'false') . ';';
            $code .= "\n\$__pcs_form = " . var_export($formAttr, true) . ';';
            $code .= "\n\$__pcs_scope = " . var_export($scopeAttr, true) . ';';
            $code .= "\n\$__pcs_empty_label = isset(\$Taglib__empty_label) && \$Taglib__empty_label !== ''"
                . " ? (string)\$Taglib__empty_label : (string)__('未选择');";
            $code .= "\n\$__pcs_placeholder = isset(\$Taglib__placeholder) && \$Taglib__placeholder !== ''"
                . " ? (string)\$Taglib__placeholder : (string)__('搜索名称或编码');";
            $code .= "\n\$__pcs_value_raw = \$Taglib__value ?? '';";
            $code .= "\nif (is_array(\$__pcs_value_raw)) {"
                . " \$__pcs_value = implode(',', array_values(array_filter(array_map("
                . "static fn(\$v): string => trim((string)\$v), \$__pcs_value_raw),"
                . "static fn(string \$v): bool => \$v !== '')));"
                . "} else {"
                . " \$__pcs_value = trim((string)\$__pcs_value_raw);"
                . "}";
            $code .= "\n\$__pcs_options_raw = \$Taglib__options ?? [];";
            $code .= "\nif (is_string(\$__pcs_options_raw)) {"
                . " \$decoded = json_decode(\$__pcs_options_raw, true);"
                . " \$__pcs_options_raw = is_array(\$decoded) ? \$decoded : [];"
                . "}";
            $code .= "\nif (!is_array(\$__pcs_options_raw)) { \$__pcs_options_raw = []; }";
            $code .= "\n\$__pcs_media = \\Weline\\Framework\\Manager\\ObjectManager::getInstance("
                . "\\Weline\\Product\\Service\\ProductAdminMediaPresenter::class);";
            $code .= "\n\$__pcs_normalized = [];";
            $code .= "\nforeach (\$__pcs_options_raw as \$__pcs_item) {"
                . " if (!is_array(\$__pcs_item)) { continue; }"
                . " \$__pcs_vid = (string)(\$__pcs_item['value'] ?? \$__pcs_item['brand_id']"
                . " ?? \$__pcs_item['supplier_id'] ?? \$__pcs_item['id'] ?? '');"
                . " \$__pcs_vid = trim(\$__pcs_vid);"
                . " if (\$__pcs_vid === '' || \$__pcs_vid === '0') { continue; }"
                . " \$__pcs_label = trim((string)(\$__pcs_item['label'] ?? \$__pcs_item['name'] ?? ('#' . \$__pcs_vid)));"
                . " \$__pcs_meta = trim((string)(\$__pcs_item['meta'] ?? \$__pcs_item['code'] ?? ''));"
                . " \$__pcs_image_raw = trim((string)(\$__pcs_item['image'] ?? \$__pcs_item['image_url']"
                . " ?? \$__pcs_item['logo_url'] ?? \$__pcs_item['thumb'] ?? ''));"
                . " \$__pcs_image = \$__pcs_image_raw !== ''"
                . " ? (string)\$__pcs_media->displayableImageUrl(\$__pcs_image_raw) : '';"
                . " \$__pcs_normalized[] = ["
                . " 'value' => \$__pcs_vid,"
                . " 'label' => \$__pcs_label !== '' ? \$__pcs_label : ('#' . \$__pcs_vid),"
                . " 'meta' => \$__pcs_meta,"
                . " 'image' => \$__pcs_image,"
                . " ];"
                . "}";

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';
            $html[] = '<style data-w-product-catalog-select-style>';
            $html[] = '.weline-product-catalog-select{position:relative;min-width:220px;max-width:100%;color:var(--backend-color-text-primary,#162033)}';
            $html[] = '.weline-product-catalog-select-trigger{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;min-height:42px;padding:6px 12px;background:var(--backend-color-card-bg,var(--weline-theme-surface,transparent));border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:6px;color:inherit;text-align:left;cursor:pointer}';
            $html[] = '.weline-product-catalog-select-trigger:hover,.weline-product-catalog-select.is-open .weline-product-catalog-select-trigger{border-color:var(--backend-color-primary,#556ee6);box-shadow:0 0 0 3px color-mix(in srgb,var(--backend-color-primary,#556ee6) 22%,transparent);outline:0}';
            $html[] = '.weline-product-catalog-select-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;flex:1;min-width:0;max-height:88px;overflow:auto}';
            $html[] = '.weline-product-catalog-select-empty{color:var(--backend-color-text-secondary,#64748b);font-size:.9rem}';
            $html[] = '.weline-product-catalog-select-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:2px 8px 2px 2px;border-radius:999px;background:color-mix(in srgb,var(--backend-color-primary,#556ee6) 16%,transparent);color:var(--backend-color-primary,#556ee6);font-size:12px;line-height:1.4}';
            $html[] = '.weline-product-catalog-select-chip-thumb,.weline-product-catalog-select-item-thumb{flex:0 0 auto;width:22px;height:22px;border-radius:50%;object-fit:cover;background:color-mix(in srgb,var(--backend-color-border-default,#dbe3ef) 55%,transparent);display:block}';
            $html[] = '.weline-product-catalog-select-item-thumb{width:32px;height:32px;border-radius:8px;margin-top:1px}';
            $html[] = '.weline-product-catalog-select-chip-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
            $html[] = '.weline-product-catalog-select-chip-remove{cursor:pointer;opacity:.75;font-size:14px;line-height:1;border:0;background:transparent;color:inherit;padding:0}';
            $html[] = '.weline-product-catalog-select-chip-remove:hover{opacity:1;color:var(--backend-color-danger,#ef4444)}';
            $html[] = '.weline-product-catalog-select-chevron{color:var(--backend-color-text-secondary,#64748b);font-size:16px;line-height:1;flex:0 0 auto}';
            $html[] = '.weline-product-catalog-select-dropdown{display:none;flex-direction:column;gap:0;padding:8px;background:var(--backend-color-card-bg,var(--weline-theme-surface-raised,var(--weline-theme-surface,transparent)));border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:10px;box-shadow:0 16px 36px color-mix(in srgb,var(--backend-color-text-primary,#162033) 18%,transparent);box-sizing:border-box;overflow:hidden;min-width:280px;color:inherit}';
            $html[] = '.weline-product-catalog-select-search{display:block;width:100%;flex:0 0 auto;min-height:36px;padding:6px 10px;margin:0 0 7px;border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:6px;background:var(--backend-color-input-bg,var(--backend-color-card-bg,transparent));color:inherit;box-sizing:border-box}';
            $html[] = '.weline-product-catalog-select-list{flex:1 1 auto;min-height:160px;max-height:320px;overflow:auto;overscroll-behavior:contain;background:transparent}';
            $html[] = '.weline-product-catalog-select-item{display:flex;align-items:flex-start;gap:8px;width:100%;padding:6px 8px;border-radius:7px;color:inherit;background:transparent;border:0;text-align:left;cursor:pointer}';
            $html[] = '.weline-product-catalog-select-item:hover{background:color-mix(in srgb,var(--backend-color-primary,#556ee6) 10%,var(--backend-color-card-bg,transparent))}';
            $html[] = '.weline-product-catalog-select-item.is-selected{background:color-mix(in srgb,var(--backend-color-primary,#556ee6) 16%,var(--backend-color-card-bg,transparent))}';
            $html[] = '.weline-product-catalog-select-item-check{flex:0 0 auto;margin-top:3px;accent-color:var(--backend-color-primary,#556ee6)}';
            $html[] = '.weline-product-catalog-select-item-main{min-width:0;flex:1;display:flex;flex-direction:column;gap:1px}';
            $html[] = '.weline-product-catalog-select-item-label{font-size:.9rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
            $html[] = '.weline-product-catalog-select-item-meta{color:var(--backend-color-text-secondary,#64748b);font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
            $html[] = '.weline-product-catalog-select-empty-state{padding:10px;text-align:center;color:var(--backend-color-text-secondary,#64748b);font-size:.85rem}';
            $html[] = '.weline-product-catalog-select.is-disabled{opacity:.65;pointer-events:none}';
            $html[] = '</style>';

            $formAttrHtml = $formAttr !== ''
                ? ' form="<?= htmlspecialchars($__pcs_form, ENT_QUOTES) ?>"'
                : '';
            $scopeAttrHtml = $scopeAttr !== ''
                ? ' scope="<?= htmlspecialchars($__pcs_scope, ENT_QUOTES) ?>"'
                : '';

            $html[] = '<div class="weline-product-catalog-select ' . htmlspecialchars($class, ENT_QUOTES)
                . '" style="' . htmlspecialchars($style, ENT_QUOTES)
                . '" id="<?= htmlspecialchars($__pcs_id, ENT_QUOTES) ?>_wrapper"'
                . ' data-component="product-catalog-select"'
                . ' data-entity="<?= htmlspecialchars($__pcs_entity, ENT_QUOTES) ?>"'
                . ' data-multiple="<?= $__pcs_multiple ? \'true\' : \'false\' ?>"'
                . ' data-testid="<?= htmlspecialchars($__pcs_id, ENT_QUOTES) ?>">';
            $html[] = '  <button type="button" class="weline-product-catalog-select-trigger" id="<?= htmlspecialchars($__pcs_id, ENT_QUOTES) ?>_trigger" aria-haspopup="listbox" aria-expanded="false">';
            $html[] = '    <div class="weline-product-catalog-select-chips" id="<?= htmlspecialchars($__pcs_id, ENT_QUOTES) ?>_chips"><span class="weline-product-catalog-select-empty"><?= htmlspecialchars($__pcs_empty_label, ENT_QUOTES) ?></span></div>';
            $html[] = '    <span class="weline-product-catalog-select-chevron" aria-hidden="true">⌄</span>';
            $html[] = '  </button>';
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($__pcs_id, ENT_QUOTES) ?>" name="<?= htmlspecialchars($__pcs_name, ENT_QUOTES) ?>" value="<?= htmlspecialchars($__pcs_value, ENT_QUOTES) ?>"'
                . $formAttrHtml . $scopeAttrHtml
                . ' data-product-catalog-select-value>';
            $html[] = '  <div class="weline-product-catalog-select-dropdown" id="<?= htmlspecialchars($__pcs_id, ENT_QUOTES) ?>_dropdown" hidden>';
            $html[] = '    <input type="search" class="weline-product-catalog-select-search" id="<?= htmlspecialchars($__pcs_id, ENT_QUOTES) ?>_search" placeholder="<?= htmlspecialchars($__pcs_placeholder, ENT_QUOTES) ?>" autocomplete="off">';
            $html[] = '    <div class="weline-product-catalog-select-list" id="<?= htmlspecialchars($__pcs_id, ENT_QUOTES) ?>_list" role="listbox" aria-multiselectable="<?= $__pcs_multiple ? \'true\' : \'false\' ?>"></div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            $html[] = FloatingDropdownEmitter::script();
            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var id = <?= json_encode((string)$__pcs_id, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var emptyLabel = <?= json_encode((string)$__pcs_empty_label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var notFound = ' . json_encode($notFound, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var removeTitle = ' . json_encode($removeTitle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var onChangeCode = ' . json_encode($onChange, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var isMultiple = <?= $__pcs_multiple ? \'true\' : \'false\' ?>;';
            $html[] = 'var allowEmpty = <?= $__pcs_allow_empty ? \'true\' : \'false\' ?>;';
            $html[] = 'var options = <?= json_encode($__pcs_normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = <<<'JS'
var wrapper = document.getElementById(id + "_wrapper");
var trigger = document.getElementById(id + "_trigger");
var dropdown = document.getElementById(id + "_dropdown");
var search = document.getElementById(id + "_search");
var list = document.getElementById(id + "_list");
var chips = document.getElementById(id + "_chips");
var hidden = document.getElementById(id);
if (!wrapper || !trigger || !dropdown || !search || !list || !chips || !hidden) return;
var selected = String(hidden.value || "").split(",").map(function(v){ return String(v || "").trim(); }).filter(Boolean);
if (!isMultiple && selected.length > 1) selected = [selected[0]];
var allowedValues = null;
var open = false;
var baseline = String(hidden.value || "");
function parseIds(v){
  if (Array.isArray(v)) {
    return v.map(function(x){ return String(x == null ? "" : x).trim(); }).filter(Boolean);
  }
  return String(v || "").split(",").map(function(x){ return String(x || "").trim(); }).filter(Boolean);
}
function findOption(value){
  return options.find(function(item){ return String(item.value) === String(value); });
}
function isAllowed(value){
  if (allowedValues === null) return true;
  return allowedValues.indexOf(String(value)) >= 0;
}
function visibleOptions(){
  return options.filter(function(item){ return isAllowed(item.value); });
}
function isPicked(value){ return selected.indexOf(String(value)) >= 0; }
function syncHidden(opts){
  hidden.value = selected.join(",");
  if (opts && opts.silent) return;
  try { hidden.dispatchEvent(new Event("change", { bubbles: true })); } catch (_e) {}
}
function fireChange(){
  if (!onChangeCode) return;
  try { (new Function(onChangeCode))(); } catch (e) { console.error(e); }
}
function commitIfChanged(){
  syncHidden();
  var next = String(hidden.value || "");
  if (next === baseline) return;
  baseline = next;
  fireChange();
}
function renderChips(){
  chips.innerHTML = "";
  if (!selected.length) {
    var empty = document.createElement("span");
    empty.className = "weline-product-catalog-select-empty";
    empty.textContent = emptyLabel;
    chips.appendChild(empty);
    return;
  }
  selected.forEach(function(value){
    var opt = findOption(value);
    var chip = document.createElement("span");
    chip.className = "weline-product-catalog-select-chip";
    chip.setAttribute("data-value", value);
    if (opt && opt.image) {
      var thumb = document.createElement("img");
      thumb.className = "weline-product-catalog-select-chip-thumb";
      thumb.src = String(opt.image);
      thumb.alt = "";
      thumb.loading = "lazy";
      thumb.decoding = "async";
      chip.appendChild(thumb);
    }
    var label = document.createElement("span");
    label.className = "weline-product-catalog-select-chip-label";
    label.textContent = opt ? String(opt.label || ("#" + value)) : ("#" + value);
    var remove = document.createElement("button");
    remove.type = "button";
    remove.className = "weline-product-catalog-select-chip-remove";
    remove.setAttribute("aria-label", removeTitle);
    remove.title = removeTitle;
    remove.textContent = "×";
    remove.addEventListener("click", function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      if (!allowEmpty && selected.length <= 1) return;
      selected = selected.filter(function(item){ return String(item) !== String(value); });
      renderChips();
      renderList(search.value);
      commitIfChanged();
    });
    chip.appendChild(label);
    chip.appendChild(remove);
    chips.appendChild(chip);
  });
}
function toggleValue(value, forceChecked){
  var v = String(value || "");
  if (!v || !isAllowed(v)) return;
  var checked = typeof forceChecked === "boolean" ? forceChecked : !isPicked(v);
  if (checked) {
    if (isMultiple) {
      if (!isPicked(v)) selected.push(v);
    } else {
      selected = [v];
    }
  } else {
    if (!allowEmpty && selected.length <= 1 && isPicked(v)) return;
    selected = selected.filter(function(item){ return String(item) !== v; });
  }
}
function renderList(query){
  var q = String(query || "").trim().toLowerCase();
  var rows = visibleOptions().filter(function(item){
    if (!q) return true;
    var hay = (String(item.label || "") + " " + String(item.meta || "") + " " + String(item.value || "")).toLowerCase();
    return hay.indexOf(q) >= 0;
  });
  list.innerHTML = "";
  if (!rows.length) {
    var empty = document.createElement("div");
    empty.className = "weline-product-catalog-select-empty-state";
    empty.textContent = notFound;
    list.appendChild(empty);
    return;
  }
  rows.forEach(function(item){
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "weline-product-catalog-select-item" + (isPicked(item.value) ? " is-selected" : "");
    btn.setAttribute("role", "option");
    btn.setAttribute("aria-selected", isPicked(item.value) ? "true" : "false");
    btn.setAttribute("data-value", String(item.value));
    var check = document.createElement("input");
    check.type = isMultiple ? "checkbox" : "radio";
    check.className = "weline-product-catalog-select-item-check";
    check.checked = isPicked(item.value);
    check.tabIndex = -1;
    btn.appendChild(check);
    if (item.image) {
      var itemThumb = document.createElement("img");
      itemThumb.className = "weline-product-catalog-select-item-thumb";
      itemThumb.src = String(item.image);
      itemThumb.alt = "";
      itemThumb.loading = "lazy";
      itemThumb.decoding = "async";
      btn.appendChild(itemThumb);
    }
    var main = document.createElement("span");
    main.className = "weline-product-catalog-select-item-main";
    var lab = document.createElement("span");
    lab.className = "weline-product-catalog-select-item-label";
    lab.textContent = String(item.label || ("#" + item.value));
    main.appendChild(lab);
    if (item.meta) {
      var meta = document.createElement("span");
      meta.className = "weline-product-catalog-select-item-meta";
      meta.textContent = String(item.meta);
      main.appendChild(meta);
    }
    btn.appendChild(main);
    btn.addEventListener("click", function(ev){
      ev.preventDefault();
      toggleValue(item.value);
      renderChips();
      renderList(search.value);
      commitIfChanged();
      if (!isMultiple) close();
    });
    list.appendChild(btn);
  });
}
function openDropdown(){
  if (open) return;
  open = true;
  wrapper.classList.add("is-open");
  trigger.setAttribute("aria-expanded", "true");
  dropdown.hidden = false;
  if (window.WelineTaglibFloatingDropdown && typeof window.WelineTaglibFloatingDropdown.attach === "function") {
    window.WelineTaglibFloatingDropdown.attach(trigger, dropdown, { placement: "bottom-start" });
  } else {
    dropdown.style.display = "flex";
  }
  renderList(search.value);
  try { search.focus(); } catch (_e) {}
}
function close(){
  if (!open) return;
  open = false;
  wrapper.classList.remove("is-open");
  trigger.setAttribute("aria-expanded", "false");
  if (window.WelineTaglibFloatingDropdown && typeof window.WelineTaglibFloatingDropdown.detach === "function") {
    window.WelineTaglibFloatingDropdown.detach(dropdown);
  }
  dropdown.hidden = true;
  dropdown.style.display = "none";
  search.value = "";
}
function applySelectionFromValues(v, opts){
  opts = opts || {};
  var keepUnknown = opts.keepUnknown === true;
  var raw = parseIds(v);
  selected = [];
  raw.forEach(function(val){
    if (!val) return;
    if (!isAllowed(val)) return;
    if (findOption(val) || keepUnknown) {
      if (!isMultiple) {
        selected = [val];
      } else if (!isPicked(val)) {
        selected.push(val);
      }
    }
  });
  if (!isMultiple && selected.length > 1) selected = [selected[0]];
}
function setAllowedValues(values){
  if (values === null || typeof values === "undefined") {
    allowedValues = null;
  } else {
    allowedValues = parseIds(values);
  }
  selected = selected.filter(function(v){ return isAllowed(v); });
  wrapper.classList.toggle("is-disabled", Array.isArray(allowedValues) && allowedValues.length === 0 && visibleOptions().length === 0);
  renderChips();
  if (open) renderList(search.value);
  commitIfChanged();
}
trigger.addEventListener("click", function(ev){
  ev.preventDefault();
  if (open) close(); else openDropdown();
});
search.addEventListener("input", function(){ renderList(search.value); });
search.addEventListener("keydown", function(ev){
  if (ev.key === "Escape") { ev.preventDefault(); close(); }
});
document.addEventListener("mousedown", function(ev){
  if (!open) return;
  var t = ev.target;
  if (wrapper.contains(t) || dropdown.contains(t)) return;
  close();
});
list.addEventListener("wheel", function(ev){ ev.stopPropagation(); }, { passive: true });
applySelectionFromValues(hidden.value, { keepUnknown: false });
renderChips();
window.WelineProductCatalogSelect = window.WelineProductCatalogSelect || {};
window.WelineProductCatalogSelect[id] = {
  getValue: function(){ return String(hidden.value || ""); },
  getValues: function(){ return selected.slice(); },
  setValue: function(v, opts){
    opts = opts || {};
    applySelectionFromValues(v, opts);
    renderChips();
    if (open) renderList(search.value);
    if (opts.silent) {
      syncHidden({ silent: true });
      baseline = String(hidden.value || "");
      return;
    }
    commitIfChanged();
  },
  setOptions: function(next){
    options = Array.isArray(next) ? next.map(function(item){
      return {
        value: String(item.value || item.brand_id || item.supplier_id || item.id || ""),
        label: String(item.label || item.name || ""),
        meta: String(item.meta || item.code || ""),
        image: String(item.image || item.image_url || item.logo_url || item.thumb || "")
      };
    }).filter(function(item){ return item.value && item.value !== "0"; }) : [];
    selected = selected.filter(function(v){ return !!findOption(v) && isAllowed(v); });
    renderChips();
    if (open) renderList(search.value);
    commitIfChanged();
  },
  setAllowedValues: setAllowedValues,
  clear: function(opts){
    if (!allowEmpty && !isMultiple) return;
    selected = [];
    renderChips();
    if (open) renderList(search.value);
    if (opts && opts.silent) {
      syncHidden({ silent: true });
      baseline = "";
      return;
    }
    commitIfChanged();
  }
};
JS;
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
            '<h3><code>&lt;w:product:catalog:select&gt;</code></h3>'
            . '<p>品牌/供应商芯片选择：可搜索、胶囊展示、叉叉删除、编辑回填；'
            . '<code>multiple</code> 控制多选/单选；隐藏域为逗号分隔 ID；'
            . 'JS API：<code>window.WelineProductCatalogSelect[id]</code> 的 setValue/setAllowedValues（创建页供应商→品牌级联）。</p>',
            ENT_NOQUOTES
        );
    }
}
