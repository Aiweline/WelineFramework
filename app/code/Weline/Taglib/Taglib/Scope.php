<?php

declare(strict_types=1);

namespace Weline\Taglib\Taglib;

use Weline\Framework\App\Exception;
use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Taglib\Support\FloatingDropdownEmitter;
use Weline\Framework\View\Template;

/**
 * Public Scope tag.
 *
 * Without legacy persistence attributes it renders the canonical system Scope
 * selector. Existing container-id/url/event usage remains supported unchanged.
 *
 * 含 @lang / <?= ?> 等动态属性时 AST 会走 renderRuntimeTag；必须提供
 * runtimeCallback 直接返回 HTML，不可再吐一层 PHP 源码（否则后台页会把
 * AttributeCodeCompiler / 选择器逻辑当纯文本泄漏）。
 */
class Scope implements TaglibInterface
{
    /** @var list<string> */
    private const DECLARATIVE_ATTRS = ['value', 'options'];

    public static function name(): string
    {
        return 'scope';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'container-id' => false,
            'url' => false,
            'event' => false,
            'id' => false,
            'name' => false,
            'value' => false,
            'options' => false,
            'placeholder' => false,
            'search-placeholder' => false,
            'aria-label' => false,
            'searchable' => false,
            'default-expand-all' => false,
            'disabled' => false,
            'required' => false,
            'class' => false,
            'style' => false,
            'form' => false,
        ];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes): string {
            $isPersistence = isset($attributes['container-id'])
                || ((!empty($attributes['url']) || !empty($attributes['event']))
                    && empty($attributes['name'])
                    && empty($attributes['value'])
                    && empty($attributes['options']));

            return $isPersistence
                ? self::renderPersistence($attributes)
                : self::renderSelector($attributes);
        };
    }

    /**
     * 动态属性路径：renderRuntimeTag 必须直接返回 HTML。
     */
    public static function runtimeCallback(): callable
    {
        return static function (
            Template $template,
            string $tagKey,
            array $attributes,
            string $content,
        ): string {
            unset($content);
            if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs') {
                return '';
            }

            $attrs = self::resolveDeclarativeAttrs(
                $template,
                \is_array($attributes) ? $attributes : [],
                self::DECLARATIVE_ATTRS
            );

            return self::buildMarkup($attrs);
        };
    }

    /** @param array<string,mixed> $attributes */
    private static function renderPersistence(array $attributes): string
    {
        $url = $attributes['url'] ?? null;
        $containerId = trim((string)($attributes['container-id'] ?? ''));
        if (empty($url)) {
            $url = w_url('/taglib/backend/scope');
        }
        if ($containerId === '') {
            throw new Exception(__('Scope 标签属性 container-id 不能为空！'));
        }
        $events = preg_split('/\s+/', (string)($attributes['event'] ?? 'input change')) ?: [];
        $events = array_values(array_unique(array_filter($events, static function (string $event): bool {
            return preg_match('/^[a-z][a-z0-9:-]*$/i', $event) === 1;
        })));
        if ($events === []) {
            $events = ['input', 'change'];
        }
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<span data-w-component="scope-persistence" data-w-scope-url="%s" data-w-scope-container="%s" data-w-scope-events="%s" hidden></span>',
            $escape((string)$url),
            $escape($containerId),
            $escape(implode(' ', $events))
        );
    }

    /** @param array<string,mixed> $attributes */
    private static function renderSelector(array $attributes): string
    {
        $id = trim((string)($attributes['id'] ?? 'scope')) ?: 'scope';
        $name = trim((string)($attributes['name'] ?? 'scope')) ?: 'scope';
        $class = trim((string)($attributes['class'] ?? ''));
        $style = trim((string)($attributes['style'] ?? ''));
        $form = trim((string)($attributes['form'] ?? ''));

        $runtimeAttributes = $attributes;
        unset(
            $runtimeAttributes['container-id'],
            $runtimeAttributes['url'],
            $runtimeAttributes['event'],
            $runtimeAttributes['id'],
            $runtimeAttributes['name'],
            $runtimeAttributes['class'],
            $runtimeAttributes['style'],
            $runtimeAttributes['form']
        );
        $runtimeAttributes['id'] = $id;
        $code = AttributeCodeCompiler::attributes($runtimeAttributes);

        $phpOpen = '<' . '?php ';
        $phpClose = '?' . '>';
        $echoOpen = '<' . '?= ';

        // 静态属性路径：编译期解析 Taglib__*，再调用 buildMarkup 直出 HTML。
        return $phpOpen . $code . ' ' . $phpClose . "\n"
            . $echoOpen . '\\' . self::class . '::buildMarkup(['
            . "'id' => " . var_export($id, true) . ','
            . "'name' => " . var_export($name, true) . ','
            . "'class' => " . var_export($class, true) . ','
            . "'style' => " . var_export($style, true) . ','
            . "'form' => " . var_export($form, true) . ','
            . "'value' => \$Taglib__value ?? 'default.default.default',"
            . "'placeholder' => (string)(\$Taglib__placeholder ?? ''),"
            . "'search-placeholder' => (string)(\$Taglib__search_placeholder ?? ''),"
            . "'aria-label' => (string)(\$Taglib__aria_label ?? ''),"
            . "'searchable' => \$Taglib__searchable ?? null,"
            . "'default-expand-all' => \$Taglib__default_expand_all ?? null,"
            . "'disabled' => \$Taglib__disabled ?? null,"
            . "'required' => \$Taglib__required ?? null,"
            . "'options' => \$Taglib__options ?? null,"
            . ']) ' . $phpClose;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function buildMarkup(array $attributes): string
    {
        $id = trim((string)($attributes['id'] ?? 'scope')) ?: 'scope';
        $name = trim((string)($attributes['name'] ?? 'scope')) ?: 'scope';
        $class = trim((string)($attributes['class'] ?? ''));
        $style = trim((string)($attributes['style'] ?? ''));
        $form = trim((string)($attributes['form'] ?? ''));

        $truthy = static function (mixed $value, bool $default = false): bool {
            if ($value === null || $value === '') {
                return $default;
            }
            if ($value === true || $value === 1) {
                return true;
            }

            return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on', 'disabled', 'required'], true);
        };

        $value = \trim((string)($attributes['value'] ?? 'default.default.default'));
        if ($value === '') {
            $value = 'default.default.default';
        }
        $placeholder = \trim((string)($attributes['placeholder'] ?? ''));
        if ($placeholder === '') {
            $placeholder = (string)__('选择作用范围');
        }
        $searchPlaceholder = \trim((string)($attributes['search-placeholder'] ?? ''));
        if ($searchPlaceholder === '') {
            $searchPlaceholder = (string)__('搜索作用范围');
        }
        $ariaLabel = \trim((string)($attributes['aria-label'] ?? ''));
        if ($ariaLabel === '') {
            $ariaLabel = $placeholder;
        }
        $searchable = $truthy($attributes['searchable'] ?? null, true);
        $expandAll = $truthy($attributes['default-expand-all'] ?? null, true);
        $disabled = $truthy($attributes['disabled'] ?? null, false);
        $required = $truthy($attributes['required'] ?? null, true);
        $optionsRaw = $attributes['options'] ?? null;
        $options = [];
        if (\is_string($optionsRaw) && \trim($optionsRaw) !== '') {
            $decoded = \json_decode($optionsRaw, true);
            if (\is_array($decoded)) {
                $options = $decoded;
            }
        } elseif (\is_array($optionsRaw)) {
            $options = $optionsRaw;
        }
        $display = '';
        $title = '';
        if ($options === []) {
            $payload = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\SystemConfig\Api\Scope\ScopeSelectorCatalogInterface::class
            )->build($value);
            $options = (array)($payload['tree_options'] ?? []);
            $value = (string)($payload['selected_scope'] ?? $value);
            $display = (string)($payload['selected_label'] ?? '');
            $title = (string)($payload['selected_title'] ?? $display);
        }
        $normalize = static function (array $nodes) use (&$normalize): array {
            $normalized = [];
            foreach ($nodes as $node) {
                if (!\is_array($node)) {
                    continue;
                }
                $nodeValue = \trim((string)($node['value'] ?? ''));
                if ($nodeValue === '') {
                    continue;
                }
                $label = \trim((string)($node['label'] ?? $node['name'] ?? $nodeValue)) ?: $nodeValue;
                $nodeDisplay = \trim((string)($node['display_label'] ?? $node['displayLabel'] ?? $label)) ?: $label;
                $nodeTitle = \trim((string)($node['title_label'] ?? $node['titleLabel'] ?? $node['title'] ?? $nodeDisplay)) ?: $nodeDisplay;
                $normalized[] = [
                    'value' => $nodeValue,
                    'label' => $label,
                    'display_label' => $nodeDisplay,
                    'title_label' => $nodeTitle,
                    'kind' => \trim((string)($node['kind'] ?? '')),
                    'children' => $normalize((array)($node['children'] ?? [])),
                ];
            }

            return $normalized;
        };
        $options = $normalize($options);
        $findLabel = static function (array $nodes, string $needle) use (&$findLabel): string {
            foreach ($nodes as $node) {
                if (\hash_equals((string)$node['value'], $needle)) {
                    return (string)$node['display_label'];
                }
                $child = $findLabel((array)$node['children'], $needle);
                if ($child !== '') {
                    return $child;
                }
            }

            return '';
        };
        $findTitle = static function (array $nodes, string $needle) use (&$findTitle): string {
            foreach ($nodes as $node) {
                if (\hash_equals((string)$node['value'], $needle)) {
                    return (string)($node['title_label'] ?? $node['display_label'] ?? '');
                }
                $child = $findTitle((array)$node['children'], $needle);
                if ($child !== '') {
                    return $child;
                }
            }

            return '';
        };
        if ($display === '') {
            $display = $findLabel($options, $value);
        }
        if ($title === '') {
            $title = $findTitle($options, $value);
        }
        if ($title === '') {
            $title = $display;
        }
        if ($display === '') {
            $display = $placeholder;
        }

        $escape = static fn(string $s): string => \htmlspecialchars($s, \ENT_QUOTES, 'UTF-8');
        $renderNodes = static function (array $nodes, int $level = 1) use (
            &$renderNodes,
            $escape,
            $value,
            $expandAll
        ): string {
            $out = '';
            foreach ($nodes as $node) {
                $nodeValue = (string)$node['value'];
                $label = (string)$node['label'];
                $nodeDisplay = (string)$node['display_label'];
                $nodeTitle = (string)($node['title_label'] ?? $nodeDisplay);
                $kind = (string)$node['kind'];
                $children = (array)$node['children'];
                $hasChildren = $children !== [];
                $selected = \hash_equals($value, $nodeValue);
                $expanded = $hasChildren && $expandAll;
                $classes = 'w-tree-select-node'
                    . ($selected ? ' selected' : '')
                    . ($expanded ? ' expanded' : '');
                $out .= '<div class="' . $classes . '" role="treeitem" tabindex="-1" aria-level="' . $level . '"'
                    . ' aria-selected="' . ($selected ? 'true' : 'false') . '"'
                    . ($hasChildren ? ' aria-expanded="' . ($expanded ? 'true' : 'false') . '"' : '')
                    . ' data-w-scope-node data-value="' . $escape($nodeValue) . '"'
                    . ' data-label="' . $escape($label) . '"'
                    . ' data-display-label="' . $escape($nodeDisplay) . '"'
                    . ' data-title-label="' . $escape($nodeTitle) . '"'
                    . ' data-kind="' . $escape($kind) . '"'
                    . ' data-has-children="' . ($hasChildren ? 'true' : 'false') . '">';
                $out .= '<div class="w-tree-select-node-content" data-w-scope-option title="' . $escape($nodeTitle) . '">';
                if ($hasChildren) {
                    $out .= '<button type="button" class="w-tree-select-node-expand" data-w-scope-expand tabindex="-1"'
                        . ' aria-expanded="' . ($expanded ? 'true' : 'false') . '"'
                        . ' aria-label="' . $escape((string)($expanded ? __('折叠') : __('展开'))) . '">&#9656;</button>';
                } else {
                    $out .= '<span class="w-tree-select-node-expand" aria-hidden="true"></span>';
                }
                $out .= '<span class="w-tree-select-node-label">' . $escape($label) . '</span></div>';
                if ($hasChildren) {
                    $out .= '<div class="w-tree-select-node-children" role="group">'
                        . $renderNodes($children, $level + 1)
                        . '</div>';
                }
                $out .= '</div>';
            }

            return $out;
        };

        $shellTitle = $title !== '' ? $title : $display;
        $html = [];
        $html[] = <<<'CSS'
<style>
.w-scope-select{position:relative;display:inline-block;inline-size:100%;min-inline-size:0;font-family:inherit}
.w-scope-select .w-tree-select-trigger{display:flex;align-items:center;justify-content:space-between;gap:.5rem;inline-size:100%;min-block-size:2.25rem;padding:.4rem .65rem;border:1px solid var(--weline-theme-border,var(--backend-color-border-default,#475569));border-radius:.45rem;background:var(--weline-theme-surface,var(--backend-color-card-bg,#fff));color:var(--weline-theme-text,var(--backend-color-text-primary,#162033));cursor:pointer;text-align:start}
.w-scope-select .w-tree-select-trigger:hover,.w-scope-select.open .w-tree-select-trigger{border-color:var(--weline-theme-primary,var(--backend-color-primary,#5577ee));box-shadow:var(--weline-theme-focus-ring,0 0 0 3px color-mix(in srgb,var(--weline-theme-primary,#5577ee) 18%,transparent))}
.w-scope-select .w-tree-select-trigger:focus-visible,.w-scope-select-dropdown .w-tree-select-node:focus-visible{outline:2px solid var(--weline-theme-primary,var(--backend-color-primary,#5577ee));outline-offset:2px}
.w-scope-select .w-tree-select-display{min-inline-size:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.w-scope-select .w-tree-select-arrow{flex:0 0 auto;transition:transform .16s ease}
.w-scope-select.open .w-tree-select-arrow{transform:rotate(180deg)}
/* FloatingDropdown 会把面板挂到 body：样式必须挂在面板自身，不能依赖 .w-scope-select 后代选择器 */
.w-scope-select-dropdown{padding:.5rem;border:1px solid var(--weline-theme-border,var(--backend-color-border-default,#475569));border-radius:.65rem;background-color:#202c3c;background-color:var(--weline-theme-surface-raised,var(--weline-theme-surface,#202c3c));background:var(--weline-theme-surface-raised,var(--weline-theme-surface,#202c3c));color:var(--weline-theme-text,var(--backend-color-text-primary,#edf2f8));box-shadow:var(--weline-theme-shadow-md,0 18px 45px rgba(15,23,42,.24));overflow:hidden;opacity:1;isolation:isolate;mix-blend-mode:normal;-webkit-backdrop-filter:none;backdrop-filter:none}
.w-scope-select-dropdown .w-tree-select-search{display:block;inline-size:100%;min-block-size:2.25rem;margin:0 0 .4rem;padding:.4rem .6rem;box-sizing:border-box;border:1px solid var(--weline-theme-border,var(--backend-color-border-default,#475569));border-radius:.4rem;background-color:#253244;background:var(--weline-theme-surface-muted,var(--backend-color-input-bg,#253244));color:inherit}
.w-scope-select-dropdown .w-tree-select-tree{max-block-size:23rem;overflow:auto;overscroll-behavior:contain;padding:.15rem;background-color:transparent}
.w-scope-select-dropdown .w-tree-select-node-content{display:flex;align-items:center;gap:.35rem;padding:.42rem .5rem;border-radius:.4rem;cursor:pointer}
.w-scope-select-dropdown .w-tree-select-node-content:hover,.w-scope-select-dropdown .w-tree-select-node.selected>.w-tree-select-node-content{background:var(--weline-theme-primary-surface,var(--backend-color-primary-light,#eef2ff))}
.w-scope-select-dropdown .w-tree-select-node-expand{display:inline-flex;align-items:center;justify-content:center;inline-size:1.25rem;block-size:1.25rem;padding:0;border:0;background:transparent;color:inherit;cursor:pointer;transition:transform .16s ease}
.w-scope-select-dropdown .w-tree-select-node.expanded>.w-tree-select-node-content .w-tree-select-node-expand{transform:rotate(90deg)}
.w-scope-select-dropdown .w-tree-select-node-label{min-inline-size:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.w-scope-select-dropdown .w-tree-select-node-children{display:none;margin-inline-start:.6rem;padding-inline-start:.65rem;border-inline-start:1px solid var(--weline-theme-border,var(--backend-color-border-default,#475569))}
.w-scope-select-dropdown .w-tree-select-node.expanded>.w-tree-select-node-children{display:block}
.w-scope-select-dropdown .w-tree-select-empty{padding:1rem;text-align:center;color:var(--weline-theme-text-muted,var(--backend-color-text-secondary,#64748b))}
.w-scope-select[data-disabled="true"]{opacity:.62;cursor:help}
/* Disabled <button> drops pointer events; route hover to the titled shell. */
.w-scope-select[data-disabled="true"] .w-tree-select-trigger{cursor:help;pointer-events:none}
</style>
CSS;

        $html[] = '<div class="' . $escape(\trim('w-scope-select w-tree-select ' . $class)) . '"'
            . ' id="' . $escape($id) . '_container"'
            . ' style="' . $escape($style) . '"'
            . ' data-component="scope-select"'
            . ' data-disabled="' . ($disabled ? 'true' : 'false') . '"'
            . ' data-value="' . $escape($value) . '"'
            . ' title="' . $escape($shellTitle) . '">'
            . '<input type="hidden"'
            . ' id="' . $escape($id) . '"'
            . ' name="' . $escape($name) . '"'
            . ' value="' . $escape($value) . '"'
            . ($form !== '' ? ' form="' . $escape($form) . '"' : '')
            . ($disabled ? ' disabled' : '')
            . ($required ? ' data-required="true"' : '')
            . '>'
            . '<button type="button"'
            . ' class="w-tree-select-trigger"'
            . ' id="' . $escape($id) . '_trigger"'
            . ' role="combobox"'
            . ' aria-haspopup="tree"'
            . ' aria-expanded="false"'
            . ' aria-controls="' . $escape($id) . '_dropdown"'
            . ' aria-label="' . $escape($ariaLabel) . '"'
            . ' title="' . $escape($shellTitle) . '"'
            . ' aria-required="' . ($required ? 'true' : 'false') . '"'
            . ($disabled ? ' disabled' : '')
            . '>'
            . '<span class="w-tree-select-display" id="' . $escape($id) . '_display" title="'
            . $escape($shellTitle) . '">' . $escape($display) . '</span>'
            . '<span class="w-tree-select-arrow" aria-hidden="true">&#9662;</span>'
            . '</button>'
            . '<div class="w-tree-select-dropdown w-scope-select-dropdown"'
            . ' id="' . $escape($id) . '_dropdown"'
            . ' role="presentation"'
            . ' hidden>';
        if ($searchable) {
            $html[] = '<input type="search"'
                . ' class="w-tree-select-search"'
                . ' id="' . $escape($id) . '_search"'
                . ' placeholder="' . $escape($searchPlaceholder) . '"'
                . ' aria-label="' . $escape($searchPlaceholder) . '"'
                . ' autocomplete="off">';
        }
        $html[] = '<div class="w-tree-select-tree"'
            . ' id="' . $escape($id) . '_tree"'
            . ' role="tree"'
            . ' aria-label="' . $escape($ariaLabel) . '">' . $renderNodes($options) . '</div>'
            . '<div class="w-tree-select-empty" id="' . $escape($id) . '_empty" hidden>'
            . $escape((string)__('未找到匹配作用范围')) . '</div>'
            . '</div></div>';

        $html[] = FloatingDropdownEmitter::script();

        $idJson = \json_encode($id, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $html[] = <<<JS
<script>(function(){
'use strict';
var id = {$idJson};
var root = document.getElementById(id + '_container');
var hidden = document.getElementById(id);
var trigger = document.getElementById(id + '_trigger');
var display = document.getElementById(id + '_display');
var dropdown = document.getElementById(id + '_dropdown');
var tree = document.getElementById(id + '_tree');
var search = document.getElementById(id + '_search');
var empty = document.getElementById(id + '_empty');
if (!root || !hidden || !trigger || !display || !dropdown || !tree || root.dataset.scopeSelectReady === '1') return;
root.dataset.scopeSelectReady = '1';
var opened = false;
var disabled = root.dataset.disabled === 'true';

function nodes(){
  return Array.prototype.slice.call(tree.querySelectorAll('[data-w-scope-node]'));
}
function directChildren(node){
  var group = node ? node.querySelector(':scope > .w-tree-select-node-children') : null;
  return group ? Array.prototype.filter.call(group.children, function(child){ return child.matches('[data-w-scope-node]'); }) : [];
}
function setExpanded(node, expanded){
  if (!node || node.dataset.hasChildren !== 'true') return;
  node.classList.toggle('expanded', expanded);
  node.setAttribute('aria-expanded', String(expanded));
  var button = node.querySelector(':scope > .w-tree-select-node-content [data-w-scope-expand]');
  if (button) button.setAttribute('aria-expanded', String(expanded));
}
function selectedNode(){
  return nodes().find(function(node){ return node.dataset.value === String(hidden.value || ''); }) || null;
}
function visibleNodes(){
  return nodes().filter(function(node){
    if (node.hidden) return false;
    var parent = node.parentElement ? node.parentElement.closest('[data-w-scope-node]') : null;
    while (parent) {
      if (!parent.classList.contains('expanded') || parent.hidden) return false;
      parent = parent.parentElement ? parent.parentElement.closest('[data-w-scope-node]') : null;
    }
    return true;
  });
}
function focusSelectedOrFirst(){
  var list = visibleNodes();
  var current = selectedNode();
  (list.indexOf(current) >= 0 ? current : list[0] || trigger).focus({preventScroll:true});
}
function syncSelection(value){
  nodes().forEach(function(node){
    var selected = node.dataset.value === value;
    node.classList.toggle('selected', selected);
    node.setAttribute('aria-selected', String(selected));
  });
  var current = nodes().find(function(node){ return node.dataset.value === value; }) || null;
  var shortLabel = current ? (current.dataset.displayLabel || current.dataset.label || value) : value;
  var fullTitle = current ? (current.dataset.titleLabel || current.dataset.displayLabel || current.dataset.label || value) : value;
  display.textContent = shortLabel;
  display.setAttribute('title', fullTitle);
  trigger.setAttribute('title', fullTitle);
  root.setAttribute('title', fullTitle);
  hidden.value = value;
  root.dataset.value = value;
}
function setValue(value, fire){
  value = String(value || '').trim();
  var target = nodes().find(function(node){ return node.dataset.value === value; }) || null;
  if (!target) return false;
  var changed = String(hidden.value || '') !== value;
  syncSelection(value);
  if (fire !== false && changed) {
    hidden.dispatchEvent(new Event('input', {bubbles:true}));
    hidden.dispatchEvent(new Event('change', {bubbles:true}));
  }
  return true;
}
function paintPortaledPanel(){
  var cs = window.getComputedStyle(trigger);
  var bg = (cs.backgroundColor || '').trim();
  if (!bg || bg === 'transparent' || bg === 'rgba(0, 0, 0, 0)') {
    bg = (cs.getPropertyValue('--weline-theme-surface-raised') || '').trim()
      || (cs.getPropertyValue('--weline-theme-surface') || '').trim()
      || (cs.getPropertyValue('--w-theme-editor-bg-card') || '').trim()
      || '#202c3c';
  }
  dropdown.style.backgroundColor = bg;
  dropdown.style.color = cs.color || '';
  dropdown.style.opacity = '1';
  dropdown.style.isolation = 'isolate';
  dropdown.style.mixBlendMode = 'normal';
  dropdown.style.webkitBackdropFilter = 'none';
  dropdown.style.backdropFilter = 'none';
}
function mount(){
  window.WelineTaglibFloatingDropdown.mount(trigger, dropdown, {
    minWidth: Math.max(320, trigger.getBoundingClientRect().width || 0),
    maxWidth: 560,
    preferredHeight: 440,
    zIndex: 4400,
    gap: 6,
    scrollContainer: '.w-tree-select-tree'
  });
  paintPortaledPanel();
}
function open(){
  if (disabled || opened) return;
  opened = true;
  root.classList.add('open');
  trigger.setAttribute('aria-expanded', 'true');
  dropdown.hidden = false;
  requestAnimationFrame(function(){
    mount();
    if (search) search.focus({preventScroll:true});
    else focusSelectedOrFirst();
  });
}
function close(){
  if (!opened) return;
  opened = false;
  root.classList.remove('open');
  trigger.setAttribute('aria-expanded', 'false');
  window.WelineTaglibFloatingDropdown.unmount(dropdown);
  dropdown.hidden = true;
  if (search) {
    search.value = '';
    filter('');
  }
}
function setDisabled(next){
  disabled = next === true || next === 1 || ['1','true','disabled'].indexOf(String(next || '').toLowerCase()) >= 0;
  root.dataset.disabled = String(disabled);
  hidden.disabled = disabled;
  trigger.disabled = disabled;
  trigger.setAttribute('aria-disabled', String(disabled));
  if (disabled) close();
}
function filterNode(node, query){
  var own = ((node.dataset.label || '') + ' ' + (node.dataset.displayLabel || '') + ' ' + (node.dataset.value || '')).toLowerCase().indexOf(query) >= 0;
  var childMatch = false;
  directChildren(node).forEach(function(child){ if (filterNode(child, query)) childMatch = true; });
  var matched = query === '' || own || childMatch;
  node.hidden = !matched;
  if (query && childMatch) setExpanded(node, true);
  return matched;
}
function filter(query){
  query = String(query || '').trim().toLowerCase();
  Array.prototype.filter.call(tree.children, function(child){ return child.matches('[data-w-scope-node]'); })
    .forEach(function(node){ filterNode(node, query); });
  empty.hidden = visibleNodes().length > 0;
}
function activate(node){
  if (!node || !setValue(node.dataset.value, true)) return;
  close();
  trigger.focus({preventScroll:true});
}
function focusByOffset(node, offset){
  var list = visibleNodes();
  var index = Math.max(0, list.indexOf(node));
  var next = list[Math.max(0, Math.min(list.length - 1, index + offset))];
  if (next) next.focus({preventScroll:true});
}

trigger.addEventListener('click', function(){ opened ? close() : open(); });
trigger.addEventListener('keydown', function(event){
  if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') {
    event.preventDefault();
    open();
  } else if (event.key === 'Escape') {
    event.preventDefault();
    close();
  }
});
tree.addEventListener('click', function(event){
  var expand = event.target.closest('[data-w-scope-expand]');
  var node = event.target.closest('[data-w-scope-node]');
  if (!node) return;
  if (expand) {
    event.stopPropagation();
    setExpanded(node, !node.classList.contains('expanded'));
    return;
  }
  if (event.target.closest('[data-w-scope-option]')) activate(node);
});
tree.addEventListener('keydown', function(event){
  var node = event.target.closest('[data-w-scope-node]');
  if (!node) return;
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault();
    focusByOffset(node, event.key === 'ArrowDown' ? 1 : -1);
  } else if (event.key === 'Home' || event.key === 'End') {
    event.preventDefault();
    var list = visibleNodes();
    var next = event.key === 'Home' ? list[0] : list[list.length - 1];
    if (next) next.focus({preventScroll:true});
  } else if (event.key === 'ArrowRight' && node.dataset.hasChildren === 'true') {
    event.preventDefault();
    if (!node.classList.contains('expanded')) setExpanded(node, true);
    else {
      var child = directChildren(node).find(function(candidate){ return !candidate.hidden; });
      if (child) child.focus({preventScroll:true});
    }
  } else if (event.key === 'ArrowLeft') {
    event.preventDefault();
    if (node.dataset.hasChildren === 'true' && node.classList.contains('expanded')) setExpanded(node, false);
    else {
      var parent = node.parentElement ? node.parentElement.closest('[data-w-scope-node]') : null;
      if (parent) parent.focus({preventScroll:true});
    }
  } else if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    activate(node);
  } else if (event.key === 'Escape') {
    event.preventDefault();
    close();
    trigger.focus({preventScroll:true});
  }
});
if (search) {
  search.addEventListener('input', function(){ filter(search.value); });
  search.addEventListener('keydown', function(event){
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      focusSelectedOrFirst();
    } else if (event.key === 'Escape') {
      event.preventDefault();
      close();
      trigger.focus({preventScroll:true});
    }
  });
}
document.addEventListener('click', function(event){
  if (opened && !root.contains(event.target) && !dropdown.contains(event.target)) close();
});
window.addEventListener('resize', function(){ if (opened) mount(); });
window.addEventListener('scroll', function(){ if (opened) mount(); }, true);
window.WelineScopeSelect = window.WelineScopeSelect || {};
window.WelineScopeSelect[id] = {
  getValue: function(){ return String(hidden.value || ''); },
  setValue: setValue,
  setDisabled: setDisabled,
  open: open,
  close: close
};
setDisabled(disabled);
syncSelection(String(hidden.value || ''));
})();</script>
JS;

        return implode("\n", $html);
    }

    /**
     * renderRuntimeTag 下静态声明式属性（value="selected_scope"）需从 Template::getData 解析。
     *
     * @param array<string, mixed> $attributes
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function resolveDeclarativeAttrs(Template $template, array $attributes, array $keys): array
    {
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $attributes) || !\is_string($attributes[$key])) {
                continue;
            }
            $raw = \trim($attributes[$key]);
            if ($raw === '' || \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $raw) !== 1) {
                continue;
            }
            $data = $template->getData($raw);
            if ($data === null && \function_exists('w_env')) {
                $wenv = \w_env('template.' . $raw);
                if ($wenv !== null) {
                    $data = $wenv;
                }
            }
            if ($data === null) {
                continue;
            }
            if (\is_array($data)) {
                $attributes[$key] = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            } elseif (\is_scalar($data)) {
                $attributes[$key] = (string)$data;
            }
        }

        return $attributes;
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
        return <<<'DOC'
<h3><code>&lt;w:scope&gt;</code></h3>
<p>默认渲染系统 Scope 树形选择器；SystemConfig 提供 Global 与规范身份，Websites 等身份所有者通过公共目录贡献 Website / Store / Channel。</p>
<p>含 <code>@lang</code> / 动态属性时走 <code>runtimeCallback</code> 直接出 HTML。</p>
<pre>&lt;w:scope id="scopeSelect" name="scope" value="selected_scope" searchable="true" /&gt;</pre>
<p>兼容旧持久化用法：</p>
<pre>&lt;w:scope url="@backend-url('backend/user-data')" container-id="product-container" event="change click" /&gt;</pre>
DOC;
    }
}
