<?php

declare(strict_types=1);

namespace Weline\Websites\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 站点选择标签
 *
 * 使用示例：
 * <w:websites:website:select
 *     id="seo_website_ids"
 *     name="website_ids"
 *     options="websiteSelectOptionsJson"
 *     multiple="true"
 *     empty-label="@lang(请选择站点)"
 *     placeholder="@lang(搜索站点名称或编码)"
 * />
 */
class WebsiteSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'websites:website:select';
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
            'allow-empty' => false,
            'clearable' => false,
            'on-select' => false,
            'on-change' => false,
            'form' => false,
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
            $multipleRaw = (string)($attributes['multiple'] ?? 'false');
            $isMultiple = in_array(strtolower(trim($multipleRaw)), ['true', '1', 'yes'], true);
            $allowEmptyRaw = (string)($attributes['allow-empty'] ?? 'false');
            $allowEmpty = in_array(strtolower(trim($allowEmptyRaw)), ['true', '1', 'yes'], true);
            $clearableRaw = (string)($attributes['clearable'] ?? ($allowEmpty && !$isMultiple ? 'true' : 'false'));
            $clearable = in_array(strtolower(trim($clearableRaw)), ['true', '1', 'yes'], true);
            $onSelect = (string)($attributes['on-select'] ?? '');
            $onChange = (string)($attributes['on-change'] ?? '');
            $formAttr = (string)($attributes['form'] ?? '');
            $idLiteral = (string)$attributes['id'];
            $nameLiteral = (string)($attributes['name'] ?? 'website_ids');

            $attrs = $attributes;
            unset($attrs['id'], $attrs['name'], $attrs['form'], $attrs['class'], $attrs['style'], $attrs['on-select'], $attrs['on-change'], $attrs['allow-empty'], $attrs['clearable'], $attrs['multiple']);
            $attrs['id'] = $idLiteral;
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);
            $multiFlag = $isMultiple ? 'true' : 'false';
            $emptyNotFound = (string)__('未找到匹配站点');
            $clearTitle = (string)__('清空');
            $defaultEmptyLabel = (string)__('请选择站点');
            $defaultPlaceholder = (string)__('搜索站点名称或编码');

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';
            $html[] = '<?php $__wss_id = ' . \var_export($idLiteral, true) . '; $__wss_name = ' . \var_export($nameLiteral, true) . '; $__wss_form = ' . \var_export($formAttr, true) . '; $__wss_default_empty = ' . \var_export($defaultEmptyLabel, true) . '; $__wss_default_placeholder = ' . \var_export($defaultPlaceholder, true) . '; ?>';
            $html[] = <<<'PHP'
<?php
$__wss_empty_label = \trim((string)($Taglib__empty_label ?? ''));
if ($__wss_empty_label === '') {
    $__wss_empty_label = (string)$__wss_default_empty;
}
$__wss_placeholder = \trim((string)($Taglib__placeholder ?? ''));
if ($__wss_placeholder === '') {
    $__wss_placeholder = (string)$__wss_default_placeholder;
}
?>
PHP;
            $html[] = '<style>';
            $html[] = '.weline-website-select{position:relative;width:100%;min-width:0;color:var(--weline-theme-text,var(--backend-color-text-primary,#162033))}';
            $html[] = '.weline-website-trigger{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;min-height:var(--weline-control-height,42px);padding:8px 12px;background:var(--weline-theme-surface,var(--backend-color-card-bg,#fff));border:1px solid var(--weline-theme-border-strong,var(--backend-color-border-default,#dbe3ef));border-radius:var(--weline-radius-md,6px);color:var(--weline-theme-text,var(--backend-color-text-primary,#162033));text-align:left;cursor:pointer}';
            $html[] = '.weline-website-trigger:hover,.weline-website-select.is-open .weline-website-trigger{border-color:var(--weline-theme-primary,var(--backend-color-primary,#556ee6));box-shadow:var(--weline-theme-focus-ring,0 0 0 3px color-mix(in srgb,var(--weline-theme-primary,#556ee6) 26%,transparent));outline:0}';
            $html[] = '.weline-website-tags{display:flex;flex-wrap:wrap;gap:6px;align-items:center;flex:1;min-width:0;max-height:72px;overflow-x:auto;overflow-y:auto;-webkit-overflow-scrolling:touch}';
            $html[] = '.weline-website-empty{color:var(--weline-theme-text-subtle,var(--backend-color-text-secondary,#64748b));font-size:13px}';
            $html[] = '.weline-website-tag{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:2px 8px;border-radius:var(--weline-radius-round,999px);background:var(--weline-theme-primary-surface,color-mix(in srgb,var(--weline-theme-primary,#556ee6) 14%,transparent));color:var(--weline-theme-primary,var(--backend-color-primary,#556ee6));font-size:12px;line-height:1.4;white-space:nowrap}';
            $html[] = '.weline-website-tag-id,.weline-website-item-id{flex:0 0 auto;color:var(--weline-theme-text-muted,var(--backend-color-text-secondary,#64748b));font-size:11px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1.2;padding:1px 6px;border:1px solid var(--weline-theme-border,var(--backend-color-border-default,#dbe3ef));border-radius:var(--weline-radius-round,999px);background:var(--weline-theme-surface-muted,var(--backend-color-bg-secondary,#f8fafc))}';
            $html[] = '.weline-website-tag-label{min-width:0;overflow:hidden;text-overflow:ellipsis}';
            $html[] = '.weline-website-tag-meta{color:var(--weline-theme-text-muted,var(--backend-color-text-secondary,#64748b));font-size:11px;padding:1px 6px;border:1px solid var(--weline-theme-border,var(--backend-color-border-default,#dbe3ef));border-radius:var(--weline-radius-round,999px);background:var(--weline-theme-surface-muted,var(--backend-color-bg-secondary,#f8fafc))}';
            $html[] = '.weline-website-tag-remove{margin-left:2px;cursor:pointer;opacity:.75;font-size:14px;line-height:1;color:inherit}';
            $html[] = '.weline-website-tag-remove:hover{opacity:1;color:var(--weline-theme-danger,#dc3545)}';
            $html[] = '.weline-website-actions{display:inline-flex;align-items:center;gap:4px;flex:0 0 auto}';
            $html[] = '.weline-website-clear{border:0;background:transparent;color:var(--weline-theme-text-subtle,#94a3b8);cursor:pointer;font-size:16px;line-height:1;padding:0}';
            $html[] = '.weline-website-clear:hover{color:var(--weline-theme-danger,#ef4444)}';
            $html[] = '.weline-website-chevron{color:var(--weline-theme-text-muted,var(--backend-color-text-secondary,#64748b));font-size:18px;line-height:1;flex:0 0 auto}';
            $html[] = '.weline-website-dropdown{display:none;padding:8px;background:var(--weline-theme-surface-raised,var(--weline-theme-surface,var(--backend-color-card-bg,#fff)));border:1px solid var(--weline-theme-border,var(--backend-color-border-default,#dbe3ef));border-radius:var(--weline-radius-lg,10px);box-shadow:var(--weline-theme-shadow-md,0 16px 36px rgba(15,23,42,.16));box-sizing:border-box;overflow:hidden;color:var(--weline-theme-text,var(--backend-color-text-primary,#162033))}';
            $html[] = '.weline-website-search{display:block;width:100%;min-height:36px;padding:6px 10px;margin:0 0 7px;border:1px solid var(--weline-theme-border,var(--backend-color-border-default,#dbe3ef));border-radius:var(--weline-radius-sm,6px);background:var(--weline-theme-surface-muted,var(--backend-color-bg-secondary,#f8fafc));color:var(--weline-theme-text,var(--backend-color-text-primary,#162033));box-sizing:border-box;flex:0 0 auto}';
            $html[] = '.weline-website-search::placeholder{color:var(--weline-theme-text-subtle,var(--backend-color-text-secondary,#64748b))}';
            $html[] = '.weline-website-search:focus{border-color:var(--weline-theme-focus,var(--weline-theme-primary,#556ee6));box-shadow:var(--weline-theme-focus-ring,0 0 0 3px color-mix(in srgb,var(--weline-theme-primary,#556ee6) 26%,transparent));outline:0}';
            $html[] = '.weline-website-list{display:block;flex:1 1 auto;min-height:0;height:280px;max-height:280px;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;touch-action:pan-y;scrollbar-color:var(--weline-theme-border-strong,var(--backend-color-border-default,#aeb8c7)) var(--weline-theme-surface-muted,transparent)}';
            $html[] = '.weline-website-item{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:8px 10px;border:0;border-radius:var(--weline-radius-sm,7px);background:transparent;color:var(--weline-theme-text,var(--backend-color-text-primary,#162033));text-align:left;cursor:pointer}';
            $html[] = '.weline-website-item:hover{background:var(--weline-theme-surface-hover,var(--backend-color-bg-tertiary,#f1f5f9));outline:0}';
            $html[] = '.weline-website-item.is-selected{background:var(--weline-theme-primary-surface,color-mix(in srgb,var(--weline-theme-primary,#556ee6) 14%,transparent));outline:0}';
            $html[] = '.weline-website-item-copy{min-width:0;display:flex;align-items:center;gap:8px}';
            $html[] = '.weline-website-item-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
            $html[] = '.weline-website-item-meta{color:var(--weline-theme-text-muted,var(--backend-color-text-secondary,#64748b));font-size:12px;white-space:nowrap}';
            $html[] = '.weline-website-check{color:var(--weline-theme-primary,var(--backend-color-primary,#556ee6));font-weight:700;opacity:0}';
            $html[] = '.weline-website-item.is-selected .weline-website-check{opacity:1}';
            $html[] = '.weline-website-empty-state{padding:7px 10px;border-radius:var(--weline-radius-sm,7px);text-align:center;color:var(--weline-theme-text-muted,var(--backend-color-text-secondary,#64748b))}';
            $html[] = '</style>';

            $html[] = '<div class="weline-website-select ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '" id="<?= htmlspecialchars($__wss_id, ENT_QUOTES, \'UTF-8\') ?>_wrapper" data-multiple="' . $multiFlag . '" data-component="website-select">';
            $html[] = '  <button type="button" class="weline-website-trigger" id="<?= htmlspecialchars($__wss_id, ENT_QUOTES, \'UTF-8\') ?>_trigger" aria-haspopup="listbox" aria-expanded="false">';
            $html[] = '      <div class="weline-website-tags" id="<?= htmlspecialchars($__wss_id, ENT_QUOTES, \'UTF-8\') ?>_tags"><span class="weline-website-empty" id="<?= htmlspecialchars($__wss_id, ENT_QUOTES, \'UTF-8\') ?>_display"><?= htmlspecialchars(trim((string)($Taglib__display ?? \'\'), "\'\"") !== \'\' ? trim((string)$Taglib__display, "\'\"") : $__wss_empty_label, ENT_QUOTES, \'UTF-8\') ?></span></div>';
            $html[] = '      <span class="weline-website-actions">';
            if ($clearable) {
                $html[] = '        <span class="weline-website-clear" id="<?= htmlspecialchars($__wss_id, ENT_QUOTES, \'UTF-8\') ?>_clear" title="' . htmlspecialchars($clearTitle, ENT_QUOTES, 'UTF-8') . '" hidden>&times;</span>';
            }
            $html[] = '        <span class="weline-website-chevron" aria-hidden="true">⌄</span>';
            $html[] = '      </span>';
            $html[] = '  </button>';
            $formAttrHtml = $formAttr !== ''
                ? ' form="<?= htmlspecialchars($__wss_form, ENT_QUOTES, \'UTF-8\') ?>"'
                : '';
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($__wss_id, ENT_QUOTES, \'UTF-8\') ?>_value" name="<?= htmlspecialchars($__wss_name, ENT_QUOTES, \'UTF-8\') ?>" value="<?= htmlspecialchars((string)($Taglib__value ?? \'\'), ENT_QUOTES, \'UTF-8\') ?>"' . $formAttrHtml . ' data-website-select-value>';
            $html[] = '  <div class="weline-website-dropdown" id="<?= htmlspecialchars($__wss_id, ENT_QUOTES, \'UTF-8\') ?>_dropdown" hidden>';
            $html[] = '      <input type="search" class="weline-website-search" id="<?= htmlspecialchars($__wss_id, ENT_QUOTES, \'UTF-8\') ?>_search" placeholder="<?= htmlspecialchars($__wss_placeholder, ENT_QUOTES, \'UTF-8\') ?>" aria-label="<?= htmlspecialchars($__wss_empty_label, ENT_QUOTES, \'UTF-8\') ?>" autocomplete="off">';
            $html[] = '      <div class="weline-website-list" id="<?= htmlspecialchars($__wss_id, ENT_QUOTES, \'UTF-8\') ?>_list" role="listbox" aria-label="<?= htmlspecialchars($__wss_empty_label, ENT_QUOTES, \'UTF-8\') ?>"></div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            $html[] = \Weline\Framework\View\Taglib\Support\FloatingDropdownEmitter::script();
            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var id = <?= json_encode((string)$__wss_id, JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var isMultiple = ' . ($isMultiple ? 'true' : 'false') . ';';
            $html[] = 'var allowEmpty = ' . ($allowEmpty ? 'true' : 'false') . ';';
            $html[] = 'var clearable = ' . ($clearable ? 'true' : 'false') . ';';
            $html[] = 'var emptyLabel = <?= json_encode((string)$__wss_empty_label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var emptyNotFound = ' . json_encode($emptyNotFound, JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var onSelectFn = ' . json_encode($onSelect, JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var onChangeCode = ' . json_encode($onChange, JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var optionsRaw = <?= json_encode($Taglib__options ?? "[]", JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var wrapper = document.getElementById(id + "_wrapper");';
            $html[] = 'var trigger = document.getElementById(id + "_trigger");';
            $html[] = 'var dropdown = document.getElementById(id + "_dropdown");';
            $html[] = 'var search = document.getElementById(id + "_search");';
            $html[] = 'var list = document.getElementById(id + "_list");';
            $html[] = 'var tags = document.getElementById(id + "_tags");';
            $html[] = 'var hidden = document.getElementById(id + "_value");';
            $html[] = 'var clearBtn = document.getElementById(id + "_clear");';
            $html[] = 'var options = [];';
            $html[] = 'var selected = [];';
            $html[] = 'var floatingOpened = false;';
            $html[] = 'function parseOptions(raw){';
            $html[] = '  if (Array.isArray(raw)) return raw;';
            $html[] = '  if (typeof raw !== "string" || !raw.trim()) return [];';
            $html[] = '  try { var parsed = JSON.parse(raw); return Array.isArray(parsed) ? parsed : []; } catch(e) { return []; }';
            $html[] = '}';
            $html[] = 'function formatWebsiteIdText(value){';
            $html[] = '  var raw = String(value == null ? "" : value).trim();';
            $html[] = '  if (!raw) return "";';
            $html[] = '  return raw.charAt(0) === "#" ? raw : ("#" + raw);';
            $html[] = '}';
            $html[] = 'function normalizeOptions(items){';
            $html[] = '  return (items || []).map(function(item){';
            $html[] = '    var value = String(item.value != null ? item.value : (item.website_id != null ? item.website_id : (item.id != null ? item.id : "")));';
            $html[] = '    var idSource = item.website_id != null ? item.website_id : (item.id != null && item.value == null ? item.id : "");';
            $html[] = '    var idText = "";';
            $html[] = '    if (idSource !== "" && idSource != null) { idText = formatWebsiteIdText(idSource); }';
            $html[] = '    else if (/^\\d+$/.test(value)) { idText = formatWebsiteIdText(value); }';
            $html[] = '    var label = String(item.label || item.name || "").trim();';
            $html[] = '    if (idText && label === idText) { label = ""; }';
            $html[] = '    else if (idText && label.indexOf(idText + " ") === 0) { label = label.slice(idText.length).trim(); }';
            $html[] = '    return {';
            $html[] = '      value: value,';
            $html[] = '      idText: idText,';
            $html[] = '      label: label,';
            $html[] = '      meta: String(item.meta || item.code || ""),';
            $html[] = '      group: String(item.group || ""),';
            $html[] = '      raw: item';
            $html[] = '    };';
            $html[] = '  }).filter(function(item){ return item.value !== ""; });';
            $html[] = '}';
            $html[] = 'function normalizeSelectedValue(value){';
            $html[] = '  var raw = String(value == null ? "" : value).trim();';
            $html[] = '  if (!raw || raw === "\'\'" || raw === \'""\') return "";';
            $html[] = '  if ((raw.charAt(0) === "\'" && raw.charAt(raw.length - 1) === "\'") || (raw.charAt(0) === \'"\' && raw.charAt(raw.length - 1) === \'"\')) {';
            $html[] = '    raw = raw.slice(1, -1).trim();';
            $html[] = '  }';
            $html[] = '  return raw;';
            $html[] = '}';
            $html[] = 'function readInitialSelection(){';
            $html[] = '  var raw = normalizeSelectedValue(hidden.value || "");';
            $html[] = '  if (!raw) return [];';
            $html[] = '  return raw.split(",").map(function(v){ return normalizeSelectedValue(v); }).filter(function(v){ return v !== ""; });';
            $html[] = '}';
            $html[] = 'function emitChange(item){';
            $html[] = '  try { hidden.dispatchEvent(new Event("change", { bubbles: true })); } catch(e) {}';
            $html[] = '  if (onSelectFn && typeof window[onSelectFn] === "function") {';
            $html[] = '    window[onSelectFn](item || null, { selected: selected.slice(), values: selected.slice() });';
            $html[] = '  }';
            $html[] = '  if (onChangeCode) { try { (new Function(onChangeCode))(); } catch(e) { console.error(e); } }';
            $html[] = '}';
            $html[] = 'function isPicked(value){ return selected.indexOf(String(value)) > -1; }';
            $html[] = 'function syncHidden(){ hidden.value = selected.join(","); if (clearBtn) clearBtn.hidden = !(clearable && !isMultiple && selected.length > 0); }';
            $html[] = 'function escapeHtml(str){ var div = document.createElement("div"); div.appendChild(document.createTextNode(String(str || ""))); return div.innerHTML; }';
            $html[] = 'function renderTags(){';
            $html[] = '  tags.innerHTML = "";';
            $html[] = '  if (selected.length === 0) {';
            $html[] = '    var empty = document.createElement("span"); empty.className = "weline-website-empty"; empty.id = id + "_display"; empty.textContent = emptyLabel; tags.appendChild(empty); if (clearBtn) clearBtn.hidden = true; return;';
            $html[] = '  }';
            $html[] = '  selected.forEach(function(value){';
            $html[] = '    var item = options.find(function(opt){ return opt.value === value; });';
            $html[] = '    if (!item) return;';
            $html[] = '    var tag = document.createElement("span"); tag.className = "weline-website-tag";';
            $html[] = '    if (item.idText) { var idBadge = document.createElement("span"); idBadge.className = "weline-website-tag-id"; idBadge.textContent = item.idText; tag.appendChild(idBadge); }';
            $html[] = '    if (item.label) { var label = document.createElement("span"); label.className = "weline-website-tag-label"; label.textContent = item.label; tag.appendChild(label); }';
            $html[] = '    else if (!item.idText) { var fallback = document.createElement("span"); fallback.className = "weline-website-tag-label"; fallback.textContent = item.value; tag.appendChild(fallback); }';
            $html[] = '    if (item.meta) { var meta = document.createElement("span"); meta.className = "weline-website-tag-meta"; meta.textContent = item.meta; tag.appendChild(meta); }';
            $html[] = '    if (isMultiple) {';
            $html[] = '      var rm = document.createElement("span"); rm.className = "weline-website-tag-remove"; rm.innerHTML = "&times;"; rm.title = "移除";';
            $html[] = '      rm.addEventListener("click", function(e){ e.stopPropagation(); selected = selected.filter(function(v){ return v !== value; }); syncHidden(); renderTags(); renderList(search.value || ""); emitChange(null); });';
            $html[] = '      tag.appendChild(rm);';
            $html[] = '    }';
            $html[] = '    tags.appendChild(tag);';
            $html[] = '  });';
            $html[] = '  if (clearBtn) clearBtn.hidden = !(clearable && !isMultiple && selected.length > 0);';
            $html[] = '}';
            $html[] = 'function renderList(keyword){';
            $html[] = '  var kw = String(keyword || "").trim().toLowerCase();';
            $html[] = '  var filtered = options.filter(function(item){';
            $html[] = '    return !kw || item.label.toLowerCase().indexOf(kw) > -1 || item.meta.toLowerCase().indexOf(kw) > -1 || item.value.toLowerCase().indexOf(kw) > -1 || String(item.idText || "").toLowerCase().indexOf(kw) > -1;';
            $html[] = '  });';
            $html[] = '  var html = "";';
            $html[] = '  if (allowEmpty && !isMultiple && !kw) {';
            $html[] = '    var emptyCls = selected.length === 0 ? " is-selected" : "";';
            $html[] = '    html += \'<button type="button" class="weline-website-item\' + emptyCls + \'" data-value=""><span class="weline-website-item-copy"><span class="weline-website-item-label">\' + escapeHtml(emptyLabel) + \'</span></span><span class="weline-website-check" aria-hidden="true">✓</span></button>\';';
            $html[] = '  }';
            $html[] = '  if (filtered.length === 0 && html === "") { list.innerHTML = \'<div class="weline-website-empty-state">\' + escapeHtml(emptyNotFound) + \'</div>\'; return; }';
            $html[] = '  html += filtered.map(function(item){';
            $html[] = '    var cls = isPicked(item.value) ? " is-selected" : "";';
            $html[] = '    var idHtml = item.idText ? \'<span class="weline-website-item-id">\' + escapeHtml(item.idText) + \'</span>\' : "";';
            $html[] = '    var labelHtml = item.label ? \'<span class="weline-website-item-label">\' + escapeHtml(item.label) + \'</span>\' : (!item.idText ? \'<span class="weline-website-item-label">\' + escapeHtml(item.value) + \'</span>\' : "");';
            $html[] = '    var meta = item.meta ? \'<span class="weline-website-item-meta">\' + escapeHtml(item.meta) + \'</span>\' : "";';
            $html[] = '    return \'<button type="button" class="weline-website-item\' + cls + \'" data-value="\' + escapeHtml(item.value) + \'"><span class="weline-website-item-copy">\' + idHtml + labelHtml + meta + \'</span><span class="weline-website-check" aria-hidden="true">✓</span></button>\';';
            $html[] = '  }).join("");';
            $html[] = '  list.innerHTML = html;';
            $html[] = '  list.querySelectorAll(".weline-website-item").forEach(function(el){';
            $html[] = '    el.addEventListener("click", function(){';
            $html[] = '      var value = this.getAttribute("data-value");';
            $html[] = '      if (value === null) return;';
            $html[] = '      if (value === "" && allowEmpty && !isMultiple) { selected = []; syncHidden(); renderTags(); renderList(search.value || ""); emitChange(null); closeDropdown(); return; }';
            $html[] = '      if (!value) return;';
            $html[] = '      var pickedItem = options.find(function(opt){ return opt.value === value; }) || null;';
            $html[] = '      if (isMultiple) {';
            $html[] = '        if (isPicked(value)) { selected = selected.filter(function(v){ return v !== value; }); } else { selected.push(value); }';
            $html[] = '      } else { selected = [value]; closeDropdown(); }';
            $html[] = '      syncHidden(); renderTags(); renderList(search.value || ""); emitChange(pickedItem);';
            $html[] = '    });';
            $html[] = '  });';
            $html[] = '}';
            $html[] = 'function setOptions(nextOptions){ options = normalizeOptions(nextOptions); selected = selected.filter(function(v){ return options.some(function(opt){ return opt.value === v; }); }); if (!isMultiple && selected.length > 1) selected = selected.slice(0,1); syncHidden(); renderTags(); renderList(search.value || ""); }';
            $html[] = 'function setValue(value){';
            $html[] = '  var values = Array.isArray(value)';
            $html[] = '    ? value.map(function(v){ return normalizeSelectedValue(v); }).filter(function(v){ return v !== ""; })';
            $html[] = '    : String(value == null ? "" : value).split(",").map(function(v){ return normalizeSelectedValue(v); }).filter(function(v){ return v !== ""; });';
            $html[] = '  selected = isMultiple ? values : (values.length ? [values[0]] : []);';
            $html[] = '  syncHidden(); renderTags(); renderList(search.value || "");';
            $html[] = '}';
            $html[] = 'function getSelected(){ return selected.map(function(v){ return options.find(function(opt){ return opt.value === v; }) || { value: v, idText: formatWebsiteIdText(v), label: "", meta: "", group: "", raw: null }; }); }';
            $html[] = 'function ensureListScrollable(){';
            $html[] = '  if (!dropdown || !list) return;';
            $html[] = '  var vh = window.innerHeight || document.documentElement.clientHeight || 900;';
            $html[] = '  var triggerRect = trigger ? trigger.getBoundingClientRect() : { top: 0, bottom: 0 };';
            $html[] = '  var spaceBelow = Math.max(140, vh - triggerRect.bottom - 16);';
            $html[] = '  var spaceAbove = Math.max(140, triggerRect.top - 16);';
            $html[] = '  var panelMax = Math.min(360, Math.max(spaceBelow, spaceAbove));';
            $html[] = '  var searchH = search ? (search.getBoundingClientRect().height + 7) : 0;';
            $html[] = '  var listMax = Math.max(120, panelMax - searchH - 16);';
            $html[] = '  dropdown.style.display = "flex";';
            $html[] = '  dropdown.style.flexDirection = "column";';
            $html[] = '  dropdown.style.overflow = "hidden";';
            $html[] = '  dropdown.style.maxHeight = panelMax + "px";';
            $html[] = '  list.style.display = "block";';
            $html[] = '  list.style.flex = "1 1 auto";';
            $html[] = '  list.style.minHeight = "0";';
            $html[] = '  list.style.height = listMax + "px";';
            $html[] = '  list.style.maxHeight = listMax + "px";';
            $html[] = '  list.style.overflowX = "hidden";';
            $html[] = '  list.style.overflowY = "auto";';
            $html[] = '  list.style.overscrollBehavior = "contain";';
            $html[] = '  list.style.webkitOverflowScrolling = "touch";';
            $html[] = '  list.style.touchAction = "pan-y";';
            $html[] = '}';
            $html[] = 'function onDropdownWheel(e){';
            $html[] = '  if (!list || !floatingOpened) return;';
            $html[] = '  var delta = e.deltaY;';
            $html[] = '  if (e.deltaMode === 1) delta *= 16;';
            $html[] = '  if (e.deltaMode === 2) delta *= list.clientHeight || 280;';
            $html[] = '  if (!delta) return;';
            $html[] = '  var prev = list.scrollTop;';
            $html[] = '  list.scrollTop = prev + delta;';
            $html[] = '  e.preventDefault();';
            $html[] = '  e.stopPropagation();';
            $html[] = '}';
            $html[] = 'function positionDropdown(){';
            $html[] = '  window.WelineTaglibFloatingDropdown.mount(trigger, dropdown, { minWidth: 280, maxWidth: 420, preferredHeight: 320, zIndex: 4200, gap: 6 });';
            $html[] = '  ensureListScrollable();';
            $html[] = '}';
            $html[] = 'function focusSearch(){';
            $html[] = '  if (!search) return;';
            $html[] = '  window.setTimeout(function(){ if (floatingOpened && search) search.focus(); }, 0);';
            $html[] = '}';
            $html[] = 'function openDropdown(){';
            $html[] = '  wrapper.classList.add("is-open"); trigger.setAttribute("aria-expanded", "true");';
            $html[] = '  floatingOpened = true;';
            $html[] = '  renderList(search ? (search.value || "") : "");';
            $html[] = '  positionDropdown();';
            $html[] = '  focusSearch();';
            $html[] = '  window.addEventListener("resize", handleViewportChange);';
            $html[] = '}';
            $html[] = 'function closeDropdown(){';
            $html[] = '  if (!floatingOpened) return;';
            $html[] = '  floatingOpened = false; wrapper.classList.remove("is-open"); trigger.setAttribute("aria-expanded", "false");';
            $html[] = '  window.WelineTaglibFloatingDropdown.unmount(dropdown);';
            $html[] = '  window.removeEventListener("resize", handleViewportChange);';
            $html[] = '}';
            $html[] = 'function handleViewportChange(){ if (!floatingOpened) return; positionDropdown(); }';
            $html[] = 'if (!wrapper || !trigger || !dropdown || !search || !list || !tags || !hidden) return;';
            $html[] = 'trigger.addEventListener("click", function(e){ e.stopPropagation(); if (floatingOpened) { closeDropdown(); } else { openDropdown(); } });';
            $html[] = 'trigger.addEventListener("keydown", function(e){';
            $html[] = '  if (!floatingOpened) return;';
            $html[] = '  if (e.ctrlKey || e.metaKey || e.altKey) return;';
            $html[] = '  if (e.key.length !== 1 && e.key !== "Backspace") return;';
            $html[] = '  e.preventDefault(); e.stopPropagation();';
            $html[] = '  if (e.key === "Backspace") { search.value = String(search.value || "").slice(0, -1); }';
            $html[] = '  else { search.value = String(search.value || "") + e.key; }';
            $html[] = '  renderList(search.value || ""); ensureListScrollable(); focusSearch();';
            $html[] = '});';
            $html[] = 'if (clearBtn) {';
            $html[] = '  clearBtn.addEventListener("click", function(e){';
            $html[] = '    e.preventDefault(); e.stopPropagation();';
            $html[] = '    if (!allowEmpty || isMultiple) return;';
            $html[] = '    selected = []; syncHidden(); renderTags(); renderList(search.value || ""); emitChange(null); closeDropdown();';
            $html[] = '  });';
            $html[] = '}';
            $html[] = 'function onSearchInput(){ renderList(search.value || ""); ensureListScrollable(); }';
            $html[] = 'search.addEventListener("input", onSearchInput);';
            $html[] = 'search.addEventListener("search", onSearchInput);';
            $html[] = 'search.addEventListener("pointerdown", function(e){ e.stopPropagation(); });';
            $html[] = 'search.addEventListener("click", function(e){ e.stopPropagation(); });';
            $html[] = 'dropdown.addEventListener("wheel", onDropdownWheel, { passive: false });';
            $html[] = 'list.addEventListener("wheel", onDropdownWheel, { passive: false });';
            $html[] = 'list.addEventListener("touchmove", function(e){ e.stopPropagation(); }, { passive: true });';
            $html[] = 'document.addEventListener("click", function(e){ if (!floatingOpened) return; if (!dropdown.contains(e.target) && !trigger.contains(e.target)) { closeDropdown(); } });';
            $html[] = 'options = normalizeOptions(parseOptions(optionsRaw));';
            $html[] = 'selected = readInitialSelection(); if (!isMultiple && selected.length > 1) selected = selected.slice(0,1);';
            $html[] = 'syncHidden(); renderTags(); renderList("");';
            $html[] = 'window.WelineWebsiteSelect = window.WelineWebsiteSelect || {};';
            $html[] = 'window.WelineWebsiteSelect[id] = {';
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
            '<h3><code>&lt;w:websites:website:select&gt;</code></h3><p>站点选择标签，支持搜索、标签展示、站点 ID（#12）、多选、allow-empty（Global）与 on-change。</p>',
            ENT_NOQUOTES
        );
    }
}
