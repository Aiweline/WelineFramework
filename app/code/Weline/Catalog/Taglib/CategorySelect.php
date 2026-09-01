<?php

declare(strict_types=1);

namespace Weline\Catalog\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 分类多选标签（芯片 + 树形多选 + 快速新建），支持已选值回填。
 *
 * <w:catalog:category:select
 *     id="product-create-categories"
 *     name="category_ids"
 *     value="catalogCategoryValue"
 *     options="catalogCategoryOptionsJson"
 *     website-id="catalogCategoryWebsiteId"
 *     space="product"
 *     allow-create="true"
 *     scope="product_create_draft"
 * />
 *
 * options 项支持：value/category_id、label/name、meta/path、parent_id/pid、children。
 * 隐藏域值为逗号分隔 category_id。
 */
final class CategorySelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'catalog:category:select';
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
            'scope' => false,
            'space' => false,
            'website-id' => false,
            'allow-create' => false,
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
            $spaceLiteral = (string)($attributes['space'] ?? 'product');
            $allowCreateRaw = (string)($attributes['allow-create'] ?? 'true');
            $allowCreate = \in_array(\strtolower(\trim($allowCreateRaw)), ['true', '1', 'yes', ''], true);
            $idLiteral = (string)$attributes['id'];
            $nameLiteral = (string)($attributes['name'] ?? 'category_ids');
            $notFound = (string)\__('未找到匹配分类');
            $removeTitle = (string)\__('移除');
            $expandTitle = (string)\__('展开');
            $collapseTitle = (string)\__('折叠');
            $createTitle = (string)\__('快速新建分类');
            $createNamePh = (string)\__('新分类名称');
            $createParentRoot = (string)\__('作为顶级分类');
            $createBtn = (string)\__('新建并选中');
            $createNeedName = (string)\__('请输入分类名称');
            $createNeedWebsite = (string)\__('缺少 Website，无法新建分类');
            $createFailed = (string)\__('分类新建失败');
            $createOk = (string)\__('分类已创建');

            $attrs = $attributes;
            unset(
                $attrs['id'],
                $attrs['name'],
                $attrs['form'],
                $attrs['class'],
                $attrs['style'],
                $attrs['on-change'],
                $attrs['scope'],
                $attrs['space'],
                $attrs['allow-create'],
            );
            // website-id → website_id，供 AttributeCodeCompiler 按变量名解析
            if (isset($attrs['website-id'])) {
                $attrs['website_id'] = $attrs['website-id'];
                unset($attrs['website-id']);
            }
            $attrs['id'] = $idLiteral;
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';
            $html[] = '<?php $__ccs_id = ' . \var_export($idLiteral, true)
                . '; $__ccs_name = ' . \var_export($nameLiteral, true)
                . '; $__ccs_form = ' . \var_export($formAttr, true)
                . '; $__ccs_scope = ' . \var_export($scopeAttr, true)
                . '; $__ccs_space = ' . \var_export($spaceLiteral, true)
                . '; $__ccs_allow_create = ' . \var_export($allowCreate, true)
                . '; ?>';
            $html[] = <<<'PHP'
<?php
$__ccs_value = \trim((string)($Taglib__value ?? ''));
$__ccs_empty_label = \trim((string)($Taglib__empty_label ?? ''));
if ($__ccs_empty_label === '') {
    $__ccs_empty_label = (string)__('未选择分类');
}
$__ccs_placeholder = \trim((string)($Taglib__placeholder ?? ''));
if ($__ccs_placeholder === '') {
    $__ccs_placeholder = (string)__('搜索分类名称或路径');
}
$__ccs_website_id = (int)($Taglib__website_id ?? 0);
$__ccs_options_raw = $Taglib__options ?? null;
$__ccs_options = [];
if (\is_string($__ccs_options_raw) && $__ccs_options_raw !== '') {
    $decoded = \json_decode($__ccs_options_raw, true);
    if (\is_array($decoded)) {
        $__ccs_options = $decoded;
    }
} elseif (\is_array($__ccs_options_raw)) {
    $__ccs_options = $__ccs_options_raw;
}
if ($__ccs_options === [] && $__ccs_website_id >= 0) {
    try {
        $__ccs_tree = \w_query('catalog', 'tree', [
            'space' => $__ccs_space !== '' ? $__ccs_space : 'product',
            'website_id' => $__ccs_website_id,
            'scope_level' => 'website',
        ]);
        $__ccs_nodes = [];
        if (\is_array($__ccs_tree)) {
            if (isset($__ccs_tree['tree']) && \is_array($__ccs_tree['tree'])) {
                $__ccs_nodes = $__ccs_tree['tree'];
            } elseif (isset($__ccs_tree['items']) && \is_array($__ccs_tree['items'])) {
                $__ccs_nodes = $__ccs_tree['items'];
            } elseif (\array_is_list($__ccs_tree)) {
                $__ccs_nodes = $__ccs_tree;
            }
        }
        $__ccs_flat = [];
        $__ccs_walk = static function (array $nodes, int $parentId = 0, string $parentPath = '') use (&$__ccs_walk, &$__ccs_flat): void {
            foreach ($nodes as $node) {
                if (!\is_array($node)) {
                    continue;
                }
                $id = (int)($node['category_id'] ?? $node['id'] ?? 0);
                $name = \trim((string)($node['name'] ?? ''));
                $path = \trim((string)($node['path'] ?? ''));
                $pid = (int)($node['parent_id'] ?? $node['pid'] ?? $parentId);
                if ($path === '' && $name !== '') {
                    $path = ($parentPath !== '' ? \rtrim($parentPath, '/') : '') . '/' . \ltrim($name, '/');
                }
                if ($id > 0) {
                    $__ccs_flat[] = [
                        'value' => (string)$id,
                        'label' => $name !== '' ? $name : ('#' . $id),
                        'meta' => $path,
                        'parent_id' => (string)max(0, $pid),
                    ];
                }
                $children = $node['children'] ?? $node['items'] ?? null;
                if (\is_array($children) && $children !== []) {
                    $__ccs_walk($children, $id > 0 ? $id : $parentId, $path);
                }
            }
        };
        $__ccs_walk($__ccs_nodes);
        $__ccs_options = $__ccs_flat;
    } catch (\Throwable) {
        $__ccs_options = [];
    }
}
$__ccs_normalized = [];
$__ccs_flatten_nested = static function (array $rows, string $parentId = '0') use (&$__ccs_flatten_nested, &$__ccs_normalized): void {
    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }
        $val = (string)($row['value'] ?? $row['category_id'] ?? $row['id'] ?? '');
        if ($val === '' || $val === '0') {
            continue;
        }
        $pid = (string)($row['parent_id'] ?? $row['pid'] ?? $parentId);
        if ($pid === '') {
            $pid = '0';
        }
        $__ccs_normalized[] = [
            'value' => $val,
            'label' => (string)($row['label'] ?? $row['name'] ?? ('#' . $val)),
            'meta' => (string)($row['meta'] ?? $row['path'] ?? ''),
            'parent_id' => $pid,
        ];
        $children = $row['children'] ?? null;
        if (\is_array($children) && $children !== []) {
            $__ccs_flatten_nested($children, $val);
        }
    }
};
$__ccs_flatten_nested($__ccs_options);
?>
PHP;

            $html[] = '<style>';
            $html[] = '.weline-catalog-category-select{position:relative;min-width:240px;max-width:100%;color:var(--backend-color-text-primary,#162033)}';
            $html[] = '.weline-catalog-category-select-trigger{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;min-height:42px;padding:6px 12px;background:var(--backend-color-card-bg,var(--weline-theme-surface,transparent));border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:6px;color:inherit;text-align:left;cursor:pointer}';
            $html[] = '.weline-catalog-category-select-trigger:hover,.weline-catalog-category-select.is-open .weline-catalog-category-select-trigger{border-color:var(--backend-color-primary,#556ee6);box-shadow:0 0 0 3px color-mix(in srgb,var(--backend-color-primary,#556ee6) 22%,transparent);outline:0}';
            $html[] = '.weline-catalog-category-select-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;flex:1;min-width:0;max-height:88px;overflow:auto}';
            $html[] = '.weline-catalog-category-select-empty{color:var(--backend-color-text-secondary,#64748b);font-size:.9rem}';
            $html[] = '.weline-catalog-category-select-chip{display:inline-flex;align-items:center;gap:4px;max-width:100%;padding:2px 8px;border-radius:999px;background:color-mix(in srgb,var(--backend-color-primary,#556ee6) 16%,transparent);color:var(--backend-color-primary,#556ee6);font-size:12px;line-height:1.4}';
            $html[] = '.weline-catalog-category-select-chip-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
            $html[] = '.weline-catalog-category-select-chip-remove{cursor:pointer;opacity:.75;font-size:14px;line-height:1;border:0;background:transparent;color:inherit;padding:0}';
            $html[] = '.weline-catalog-category-select-chip-remove:hover{opacity:1;color:var(--backend-color-danger,#ef4444)}';
            $html[] = '.weline-catalog-category-select-chevron{color:var(--backend-color-text-secondary,#64748b);font-size:16px;line-height:1;flex:0 0 auto}';
            $html[] = '.weline-catalog-category-select-dropdown{display:none;flex-direction:column;gap:0;padding:8px;background:var(--backend-color-card-bg,var(--weline-theme-surface-raised,var(--weline-theme-surface,transparent)));border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:10px;box-shadow:0 16px 36px color-mix(in srgb,var(--backend-color-text-primary,#162033) 18%,transparent);box-sizing:border-box;overflow:hidden;min-width:300px;color:inherit}';
            $html[] = '.weline-catalog-category-select-search{display:block;width:100%;flex:0 0 auto;min-height:36px;padding:6px 10px;margin:0 0 7px;border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:6px;background:var(--backend-color-input-bg,var(--backend-color-card-bg,transparent));color:inherit;box-sizing:border-box}';
            $html[] = '.weline-catalog-category-select-search::placeholder{color:var(--backend-color-text-secondary,#64748b)}';
            $html[] = '.weline-catalog-category-select-list{flex:1 1 auto;min-height:200px;max-height:360px;overflow:auto;overscroll-behavior:contain;background:transparent}';
            $html[] = '.weline-catalog-category-select-create{flex:0 0 auto}';
            $html[] = '.weline-catalog-category-select-node{display:flex;align-items:flex-start;gap:4px;width:100%;padding:4px 4px;border-radius:7px;color:inherit;background:transparent}';
            $html[] = '.weline-catalog-category-select-node:hover{background:color-mix(in srgb,var(--backend-color-primary,#556ee6) 10%,var(--backend-color-card-bg,transparent))}';
            $html[] = '.weline-catalog-category-select-node.is-selected{background:color-mix(in srgb,var(--backend-color-primary,#556ee6) 16%,var(--backend-color-card-bg,transparent))}';
            $html[] = '.weline-catalog-category-select-toggle{flex:0 0 22px;width:22px;height:22px;margin-top:2px;border:0;border-radius:4px;background:transparent;color:var(--backend-color-text-secondary,#64748b);cursor:pointer;line-height:1;padding:0}';
            $html[] = '.weline-catalog-category-select-toggle.is-leaf{visibility:hidden;pointer-events:none}';
            $html[] = '.weline-catalog-category-select-check{flex:0 0 auto;margin-top:5px;accent-color:var(--backend-color-primary,#556ee6);background:transparent;color-scheme:inherit}';
            $html[] = '.weline-catalog-category-select-node-main{min-width:0;flex:1;display:flex;flex-direction:column;gap:1px;padding:2px 4px;border:0;background:transparent;color:inherit;text-align:left;cursor:pointer}';
            $html[] = '.weline-catalog-category-select-item-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.9rem;font-weight:600;color:inherit}';
            $html[] = '.weline-catalog-category-select-item-meta{color:var(--backend-color-text-secondary,#64748b);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
            $html[] = '.weline-catalog-category-select-empty-state{padding:10px;text-align:center;color:var(--backend-color-text-secondary,#64748b);font-size:.85rem}';
            $html[] = '.weline-catalog-category-select-create{margin-top:8px;padding-top:8px;border-top:1px solid var(--backend-color-border-default,#dbe3ef);display:grid;gap:6px;background:transparent}';
            $html[] = '.weline-catalog-category-select-create-title{font-size:12px;color:var(--backend-color-text-secondary,#64748b)}';
            $html[] = '.weline-catalog-category-select-create-row{display:flex;flex-wrap:wrap;gap:6px;align-items:center}';
            $html[] = '.weline-catalog-category-select-create-row input,.weline-catalog-category-select-create-row select{flex:1;min-width:120px;min-height:34px;padding:4px 8px;border:1px solid var(--backend-color-border-default,#dbe3ef);border-radius:6px;background:var(--backend-color-input-bg,var(--backend-color-card-bg,transparent));color:inherit;box-sizing:border-box;color-scheme:inherit}';
            $html[] = '.weline-catalog-category-select-create-row button{flex:0 0 auto;min-height:34px;padding:4px 12px;border:0;border-radius:6px;background:var(--backend-color-primary,#556ee6);color:#fff;cursor:pointer}';
            $html[] = '.weline-catalog-category-select-create-row button:disabled{opacity:.6;cursor:not-allowed}';
            $html[] = '</style>';

            $formAttrHtml = $formAttr !== ''
                ? ' form="<?= htmlspecialchars($__ccs_form, ENT_QUOTES) ?>"'
                : '';
            $scopeAttrHtml = $scopeAttr !== ''
                ? ' scope="<?= htmlspecialchars($__ccs_scope, ENT_QUOTES) ?>"'
                : '';

            $html[] = '<div class="weline-catalog-category-select ' . \htmlspecialchars($class, ENT_QUOTES)
                . '" style="' . \htmlspecialchars($style, ENT_QUOTES)
                . '" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_wrapper" data-component="catalog-category-select" data-allow-create="<?= $__ccs_allow_create ? \'true\' : \'false\' ?>" data-space="<?= htmlspecialchars($__ccs_space, ENT_QUOTES) ?>" data-website-id="<?= (int)$__ccs_website_id ?>">';
            $html[] = '  <button type="button" class="weline-catalog-category-select-trigger" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_trigger" aria-haspopup="tree" aria-expanded="false">';
            $html[] = '    <div class="weline-catalog-category-select-chips" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_chips"><span class="weline-catalog-category-select-empty"><?= htmlspecialchars($__ccs_empty_label, ENT_QUOTES) ?></span></div>';
            $html[] = '    <span class="weline-catalog-category-select-chevron" aria-hidden="true">⌄</span>';
            $html[] = '  </button>';
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>" name="<?= htmlspecialchars($__ccs_name, ENT_QUOTES) ?>" value="<?= htmlspecialchars($__ccs_value, ENT_QUOTES) ?>"'
                . $formAttrHtml . $scopeAttrHtml
                . ' data-catalog-category-select-value>';
            $html[] = '  <div class="weline-catalog-category-select-dropdown" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_dropdown" hidden>';
            $html[] = '    <input type="search" class="weline-catalog-category-select-search" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_search" placeholder="<?= htmlspecialchars($__ccs_placeholder, ENT_QUOTES) ?>" autocomplete="off">';
            $html[] = '    <div class="weline-catalog-category-select-list" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_list" role="tree" aria-multiselectable="true"></div>';
            if ($allowCreate) {
                $html[] = '    <div class="weline-catalog-category-select-create" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_create">';
                $html[] = '      <div class="weline-catalog-category-select-create-title">' . \htmlspecialchars($createTitle, ENT_QUOTES) . '</div>';
                $html[] = '      <div class="weline-catalog-category-select-create-row">';
                $html[] = '        <input type="text" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_create_name" maxlength="120" placeholder="' . \htmlspecialchars($createNamePh, ENT_QUOTES) . '" autocomplete="off">';
                $html[] = '        <select id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_create_parent" aria-label="' . \htmlspecialchars($createParentRoot, ENT_QUOTES) . '"></select>';
                $html[] = '        <button type="button" id="<?= htmlspecialchars($__ccs_id, ENT_QUOTES) ?>_create_btn">' . \htmlspecialchars($createBtn, ENT_QUOTES) . '</button>';
                $html[] = '      </div>';
                $html[] = '    </div>';
            }
            $html[] = '  </div>';
            $html[] = '</div>';

            $html[] = \Weline\Framework\View\Taglib\Support\FloatingDropdownEmitter::script();
            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var id = <?= json_encode((string)$__ccs_id, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var emptyLabel = <?= json_encode((string)$__ccs_empty_label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var notFound = ' . \json_encode($notFound, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var removeTitle = ' . \json_encode($removeTitle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var expandTitle = ' . \json_encode($expandTitle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var collapseTitle = ' . \json_encode($collapseTitle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var createParentRoot = ' . \json_encode($createParentRoot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var createNeedName = ' . \json_encode($createNeedName, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var createNeedWebsite = ' . \json_encode($createNeedWebsite, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var createFailed = ' . \json_encode($createFailed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var createOk = ' . \json_encode($createOk, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var onChangeCode = ' . \json_encode($onChange, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ';';
            $html[] = 'var space = <?= json_encode((string)$__ccs_space, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = 'var websiteId = <?= (int)$__ccs_website_id ?>;';
            $html[] = 'var allowCreate = <?= $__ccs_allow_create ? \'true\' : \'false\' ?>;';
            $html[] = 'var options = <?= json_encode($__ccs_normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;';
            $html[] = <<<'JS'
var wrapper = document.getElementById(id + "_wrapper");
var trigger = document.getElementById(id + "_trigger");
var dropdown = document.getElementById(id + "_dropdown");
var search = document.getElementById(id + "_search");
var list = document.getElementById(id + "_list");
var chips = document.getElementById(id + "_chips");
var hidden = document.getElementById(id);
var createName = document.getElementById(id + "_create_name");
var createParent = document.getElementById(id + "_create_parent");
var createBtn = document.getElementById(id + "_create_btn");
if (!wrapper || !trigger || !dropdown || !search || !list || !chips || !hidden) return;
var selected = String(hidden.value || "").split(",").map(function(v){ return String(v || "").trim(); }).filter(Boolean);
var expanded = {};
var open = false;
var baseline = String(hidden.value || "");
var dirty = false;
function isPicked(value){ return selected.indexOf(String(value)) >= 0; }
function parentIdOf(value){
  var opt = findOption(value);
  return opt ? String(opt.parent_id || "0") : "0";
}
function ancestorsOf(value){
  var out = [];
  var cur = String(value || "");
  var guard = 0;
  while (cur && cur !== "0" && guard++ < 64) {
    var pid = parentIdOf(cur);
    if (!pid || pid === "0" || pid === cur) break;
    out.push(pid);
    cur = pid;
  }
  return out;
}
function selectWithAncestors(value){
  var v = String(value || "");
  if (!v) return;
  if (!isPicked(v)) selected.push(v);
  ancestorsOf(v).forEach(function(pid){
    if (!isPicked(pid)) selected.push(String(pid));
    expanded[String(pid)] = true;
  });
}
function toggleNode(value, forceChecked){
  var v = String(value || "");
  if (!v) return;
  var checked = typeof forceChecked === "boolean" ? forceChecked : !isPicked(v);
  if (checked) {
    selectWithAncestors(v);
  } else {
    selected = selected.filter(function(item){ return String(item) !== v; });
  }
}
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
  if (next === baseline) { dirty = false; return; }
  baseline = next;
  dirty = false;
  fireChange();
}
function buildTree(rows){
  var byId = {};
  var roots = [];
  (rows || []).forEach(function(item){
    var value = String(item.value || "");
    if (!value) return;
    byId[value] = {
      value: value,
      label: String(item.label || ("#" + value)),
      meta: String(item.meta || ""),
      parent_id: String(item.parent_id || "0"),
      children: []
    };
  });
  Object.keys(byId).forEach(function(value){
    var node = byId[value];
    var pid = String(node.parent_id || "0");
    if (pid && pid !== "0" && byId[pid] && pid !== value) {
      byId[pid].children.push(node);
    } else {
      roots.push(node);
    }
  });
  return roots;
}
function findOption(value){
  return options.find(function(item){ return String(item.value) === String(value); });
}
function parseIds(v){
  if (Array.isArray(v)) {
    return v.map(function(x){ return String(x == null ? "" : x).trim(); }).filter(Boolean);
  }
  return String(v || "").split(",").map(function(x){ return String(x || "").trim(); }).filter(Boolean);
}
/** 按 options 内部勾选（可选祖先链）；默认丢弃不在树中的陈旧 ID，避免芯片显示 #id。 */
function applySelectionFromValues(v, opts){
  opts = opts || {};
  var keepUnknown = opts.keepUnknown === true;
  var withAncestors = opts.withAncestors !== false;
  var raw = parseIds(v);
  selected = [];
  raw.forEach(function(id){
    if (!id) return;
    if (findOption(id)) {
      if (withAncestors) {
        selectWithAncestors(id);
      } else if (!isPicked(id)) {
        selected.push(id);
      }
      return;
    }
    if (keepUnknown && !isPicked(id)) {
      selected.push(id);
    }
  });
}
function slugify(value){
  return String(value || "").trim().toLowerCase()
    .replace(/[^a-z0-9\u4e00-\u9fff]+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "") || "category";
}
function renderChips(){
  chips.innerHTML = "";
  if (!selected.length) {
    var empty = document.createElement("span");
    empty.className = "weline-catalog-category-select-empty";
    empty.textContent = emptyLabel;
    chips.appendChild(empty);
    return;
  }
  selected.forEach(function(value){
    var opt = findOption(value);
    var chip = document.createElement("span");
    chip.className = "weline-catalog-category-select-chip";
    var label = document.createElement("span");
    label.className = "weline-catalog-category-select-chip-label";
    var labelText = opt && String(opt.label || "").trim() !== "" ? String(opt.label) : ("#" + value);
    label.textContent = labelText;
    if (opt && opt.meta) label.title = String(opt.meta);
    chip.appendChild(label);
    var rm = document.createElement("button");
    rm.type = "button";
    rm.className = "weline-catalog-category-select-chip-remove";
    rm.title = removeTitle;
    rm.innerHTML = "&times;";
    rm.addEventListener("click", function(e){
      e.preventDefault(); e.stopPropagation();
      selected = selected.filter(function(v){ return String(v) !== String(value); });
      dirty = true; syncHidden(); renderChips(); renderTree(search.value);
      if (!open) commitIfChanged();
    });
    chip.appendChild(rm);
    chips.appendChild(chip);
  });
}
function nodeMatches(node, q){
  if (!q) return true;
  if (String(node.value).toLowerCase().indexOf(q) >= 0) return true;
  if (String(node.label).toLowerCase().indexOf(q) >= 0) return true;
  if (String(node.meta || "").toLowerCase().indexOf(q) >= 0) return true;
  return (node.children || []).some(function(child){ return nodeMatches(child, q); });
}
function filterTree(nodes, q){
  if (!q) return nodes;
  var out = [];
  (nodes || []).forEach(function(node){
    if (!nodeMatches(node, q)) return;
    var children = filterTree(node.children || [], q);
    out.push({
      value: node.value,
      label: node.label,
      meta: node.meta,
      parent_id: node.parent_id,
      children: children
    });
    if (children.length) expanded[node.value] = true;
  });
  return out;
}
function appendNode(node, depth, q){
  var row = document.createElement("div");
  row.className = "weline-catalog-category-select-node" + (isPicked(node.value) ? " is-selected" : "");
  row.style.paddingLeft = (4 + depth * 16) + "px";
  row.setAttribute("role", "treeitem");
  row.setAttribute("aria-selected", isPicked(node.value) ? "true" : "false");
  var hasChildren = Array.isArray(node.children) && node.children.length > 0;
  var isOpen = !!expanded[node.value] || (!!q && hasChildren);
  var toggle = document.createElement("button");
  toggle.type = "button";
  toggle.className = "weline-catalog-category-select-toggle" + (hasChildren ? "" : " is-leaf");
  toggle.title = isOpen ? collapseTitle : expandTitle;
  toggle.textContent = hasChildren ? (isOpen ? "▾" : "▸") : "";
  toggle.addEventListener("click", function(e){
    e.preventDefault(); e.stopPropagation();
    if (!hasChildren) return;
    expanded[node.value] = !isOpen;
    renderTree(search.value);
  });
  var check = document.createElement("input");
  check.type = "checkbox";
  check.className = "weline-catalog-category-select-check";
  check.checked = isPicked(node.value);
  check.tabIndex = -1;
  check.addEventListener("click", function(e){ e.stopPropagation(); });
  check.addEventListener("change", function(){
    toggleNode(node.value, !!check.checked);
    dirty = true; syncHidden(); renderChips(); renderTree(search.value);
  });
  var main = document.createElement("button");
  main.type = "button";
  main.className = "weline-catalog-category-select-node-main";
  main.innerHTML = '<span class="weline-catalog-category-select-item-label"></span>' +
    (node.meta ? '<span class="weline-catalog-category-select-item-meta"></span>' : "");
  main.querySelector(".weline-catalog-category-select-item-label").textContent = node.label;
  if (node.meta) main.querySelector(".weline-catalog-category-select-item-meta").textContent = node.meta;
  main.addEventListener("click", function(){
    toggleNode(node.value);
    dirty = true; syncHidden(); renderChips(); renderTree(search.value);
  });
  row.appendChild(toggle);
  row.appendChild(check);
  row.appendChild(main);
  list.appendChild(row);
  if (hasChildren && isOpen) {
    node.children.forEach(function(child){ appendNode(child, depth + 1, q); });
  }
}
function renderParentOptions(){
  if (!createParent) return;
  var current = String(createParent.value || "0");
  createParent.innerHTML = "";
  var rootOpt = document.createElement("option");
  rootOpt.value = "0";
  rootOpt.textContent = createParentRoot;
  createParent.appendChild(rootOpt);
  options.slice().sort(function(a, b){
    return String(a.meta || a.label).localeCompare(String(b.meta || b.label), "zh");
  }).forEach(function(item){
    var opt = document.createElement("option");
    opt.value = String(item.value);
    opt.textContent = (item.meta ? item.meta + " · " : "") + item.label;
    createParent.appendChild(opt);
  });
  if ([].some.call(createParent.options, function(o){ return o.value === current; })) {
    createParent.value = current;
  } else {
    createParent.value = "0";
  }
}
function renderTree(keyword){
  var q = String(keyword || "").trim().toLowerCase();
  list.innerHTML = "";
  var tree = filterTree(buildTree(options), q);
  if (!tree.length) {
    var empty = document.createElement("div");
    empty.className = "weline-catalog-category-select-empty-state";
    empty.textContent = notFound;
    list.appendChild(empty);
  } else {
    tree.forEach(function(node){ appendNode(node, 0, q); });
  }
  renderParentOptions();
}
function measureDropdownChrome(){
  if (!dropdown || !list) return 0;
  var total = dropdown.scrollHeight || 0;
  var listH = list.scrollHeight || 0;
  return Math.max(0, total - listH);
}
function resolveListBudget(){
  var viewport = window.visualViewport;
  var vh = viewport ? viewport.height : (window.innerHeight || document.documentElement.clientHeight || 800);
  var panelBudget = Math.max(280, Math.min(520, Math.floor(vh * 0.55)));
  return Math.max(200, Math.floor(panelBudget - measureDropdownChrome()));
}
function ensureListScrollable(){
  if (!dropdown || !list) return 0;
  var listMax = resolveListBudget();
  dropdown.style.display = "flex";
  dropdown.style.flexDirection = "column";
  dropdown.style.overflow = "hidden";
  dropdown.style.maxHeight = "none";
  dropdown.style.height = "auto";
  list.style.flex = "1 1 auto";
  list.style.minHeight = "0";
  list.style.height = listMax + "px";
  list.style.maxHeight = listMax + "px";
  list.style.overflowX = "hidden";
  list.style.overflowY = "auto";
  list.style.overscrollBehavior = "contain";
  list.style.webkitOverflowScrolling = "touch";
  list.style.touchAction = "pan-y";
  return measureDropdownChrome() + listMax;
}
function positionDropdown(){
  var panelHeight = ensureListScrollable() || 480;
  var minWidth = Math.max(320, trigger && trigger.offsetWidth ? trigger.offsetWidth : 0);
  window.WelineTaglibFloatingDropdown.mount(trigger, dropdown, {
    minWidth: minWidth,
    preferredHeight: panelHeight,
    scrollContainer: list,
    overflowY: "hidden",
    zIndex: 4200,
    gap: 4
  });
  ensureListScrollable();
}
function handleViewportChange(ev){
  if (!open) return;
  if (ev && ev.type === "scroll" && dropdown && dropdown.contains(ev.target)) return;
  positionDropdown();
}
function onDropdownWheel(e){
  if (!open || !list) return;
  if (!dropdown.contains(e.target)) return;
  var delta = e.deltaY;
  if (e.deltaMode === 1) delta *= 16;
  if (e.deltaMode === 2) delta *= list.clientHeight || 280;
  if (!delta) return;
  var maxScroll = Math.max(0, list.scrollHeight - list.clientHeight);
  if (maxScroll <= 0) {
    e.preventDefault();
    e.stopPropagation();
    return;
  }
  var prev = list.scrollTop;
  var next = Math.max(0, Math.min(maxScroll, prev + delta));
  list.scrollTop = next;
  e.preventDefault();
  e.stopPropagation();
}
function openDropdown(){
  if (open) return;
  open = true;
  wrapper.classList.add("is-open");
  trigger.setAttribute("aria-expanded", "true");
  renderTree(search.value);
  positionDropdown();
  window.addEventListener("resize", handleViewportChange);
  window.addEventListener("scroll", handleViewportChange, true);
  dropdown.addEventListener("wheel", onDropdownWheel, { passive: false });
  setTimeout(function(){ search.focus(); }, 0);
}
function close(){
  if (!open) return;
  open = false;
  wrapper.classList.remove("is-open");
  trigger.setAttribute("aria-expanded", "false");
  window.removeEventListener("resize", handleViewportChange);
  window.removeEventListener("scroll", handleViewportChange, true);
  dropdown.removeEventListener("wheel", onDropdownWheel);
  window.WelineTaglibFloatingDropdown.unmount(dropdown);
  if (dirty) commitIfChanged();
}
async function resolveCatalogAdminApi(){
  var Weline = window.Weline;
  var api = Weline && Weline.Api && Weline.Api.resource ? Weline.Api : (Weline && Weline.load ? await Weline.load("api") : null);
  if (!api || typeof api.resource !== "function") throw new Error(createFailed);
  return api.resource("catalog_category_admin");
}
async function createCategory(){
  if (!allowCreate || !createBtn || !createName) return;
  var name = String(createName.value || "").trim();
  if (!name) {
    window.Weline && window.Weline.UI && window.Weline.UI.toast && window.Weline.UI.toast.error
      ? window.Weline.UI.toast.error(createNeedName)
      : alert(createNeedName);
    return;
  }
  if (!(websiteId >= 0)) {
    window.Weline && window.Weline.UI && window.Weline.UI.toast && window.Weline.UI.toast.error
      ? window.Weline.UI.toast.error(createNeedWebsite)
      : alert(createNeedWebsite);
    return;
  }
  var pid = createParent ? String(createParent.value || "0") : "0";
  createBtn.disabled = true;
  try {
    var resource = await resolveCatalogAdminApi();
    if (typeof resource.categoryAdminSave !== "function") throw new Error(createFailed);
    var result = await resource.categoryAdminSave({
      space: space || "product",
      scope_level: "website",
      website_id: websiteId,
      store_id: 0,
      channel_id: 0,
      id: 0,
      pid: parseInt(pid, 10) || 0,
      name: name,
      code: slugify(name),
      is_active: 1
    }, { keepBusinessResult: true, silent: true });
    if (result && (result.success === false || Number(result.code || 200) >= 400)) {
      throw new Error(String(result.message || result.msg || (result.data && (result.data.message || result.data.msg)) || createFailed));
    }
    var newId = String((result && result.data && (result.data.id || result.data.category_id)) || (result && (result.id || result.category_id)) || "");
    if (!newId || newId === "0") throw new Error(createFailed);
    var parentMeta = pid !== "0" && findOption(pid) ? String(findOption(pid).meta || "") : "";
    var meta = (parentMeta ? parentMeta.replace(/\/+$/, "") : "") + "/" + slugify(name);
    options.push({ value: newId, label: name, meta: meta, parent_id: pid });
    if (pid !== "0") expanded[pid] = true;
    createName.value = "";
    dirty = true;
    selectWithAncestors(newId);
    syncHidden(); renderChips(); renderTree(search.value);
    window.Weline && window.Weline.UI && window.Weline.UI.toast && window.Weline.UI.toast.success
      ? window.Weline.UI.toast.success(createOk)
      : null;
  } catch (error) {
    var msg = error && error.message ? error.message : createFailed;
    window.Weline && window.Weline.UI && window.Weline.UI.toast && window.Weline.UI.toast.error
      ? window.Weline.UI.toast.error(msg)
      : alert(msg);
  } finally {
    createBtn.disabled = false;
  }
}
trigger.addEventListener("click", function(e){
  e.preventDefault();
  if (open) close(); else openDropdown();
});
search.addEventListener("input", function(){ renderTree(search.value); });
if (createBtn) createBtn.addEventListener("click", function(e){ e.preventDefault(); e.stopPropagation(); createCategory(); });
if (createName) createName.addEventListener("keydown", function(e){
  if (e.key === "Enter") { e.preventDefault(); e.stopPropagation(); createCategory(); }
});
document.addEventListener("click", function(e){
  if (!open) return;
  if (wrapper.contains(e.target) || dropdown.contains(e.target)) return;
  close();
});
function applyValueAndPaint(v, opts){
  opts = opts || {};
  applySelectionFromValues(v, {
    withAncestors: opts.withAncestors !== false,
    keepUnknown: opts.keepUnknown === true
  });
  if (opts.silent) {
    dirty = false;
    syncHidden({ silent: true });
    baseline = String(hidden.value || "");
    renderChips();
    renderTree(search.value);
    return selected.join(",");
  }
  dirty = true;
  syncHidden();
  renderChips();
  renderTree(search.value);
  if (!open) commitIfChanged();
  return selected.join(",");
}
window.WelineCatalogCategorySelect = window.WelineCatalogCategorySelect || {};
window.WelineCatalogCategorySelect[id] = {
  getValue: function(){ return selected.join(","); },
  getValues: function(){ return selected.slice(); },
  /** 按 options 内部勾选并重绘芯片/树；默认丢弃不在树中的 ID。 */
  setValue: function(v, opts){ return applyValueAndPaint(v, opts || {}); },
  /** 同 setValue；语义上强调「调用标签内部选中」。 */
  select: function(v, opts){ return applyValueAndPaint(v, opts || {}); },
  setOptions: function(next){
    var cur = selected.slice();
    options = Array.isArray(next) ? next.slice() : [];
    applySelectionFromValues(cur, { withAncestors: true, keepUnknown: false });
    syncHidden({ silent: true });
    baseline = String(hidden.value || "");
    renderChips();
    renderTree(search.value);
  }
};
function hydrateFromHidden(){
  applySelectionFromValues(hidden.value || "", { withAncestors: true, keepUnknown: false });
  syncHidden({ silent: true });
  baseline = String(hidden.value || "");
  dirty = false;
  renderChips();
  if (open) renderTree(search.value);
}
hidden.addEventListener("input", hydrateFromHidden);
hidden.addEventListener("change", function(){
  // 外部静默写入后可能只派发 change；与 input 对齐芯片状态，但不二次广播。
  var next = String(hidden.value || "");
  var cur = selected.join(",");
  if (next === cur) return;
  hydrateFromHidden();
});
// 初始化：先按 hidden 水合，再静默对齐；禁止空 selected 覆盖已有 value。
hydrateFromHidden();
try {
  queueMicrotask(hydrateFromHidden);
} catch (_e) {
  setTimeout(hydrateFromHidden, 0);
}
try {
  document.dispatchEvent(new CustomEvent("weline:catalog-category-select-ready", {
    bubbles: true,
    detail: { id: id }
  }));
} catch (_e) {}
JS;
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
            '<h3><code>&lt;w:catalog:category:select&gt;</code></h3>'
            . '<p>Catalog 分类多选：芯片展示、可搜索树形勾选、选叶子自动勾祖先、面板内滚动不关闭、可选快速新建（catalog_category_admin.categoryAdminSave）。'
            . '隐藏域值为逗号分隔 category_id；JS API：<code>setValue</code>/<code>select</code> 按 options 内部勾选并显示名称（丢弃未知 ID）；配合 <code>scope</code> 静默回填。'
            . '详见模块文档 <code>doc/catalog-category-select标签使用指南.md</code>。</p>',
            ENT_NOQUOTES
        );
    }
}
