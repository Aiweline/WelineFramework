<?php

declare(strict_types=1);

namespace Weline\Websites\Taglib;

/**
 * Website / Store / Channel 等 code 字段共用的可搜索单选渲染器。
 * 由具体 Taglib 包装；编译期走 AttributeCodeCompiler + echo buildMarkup，
 * 运行期（含 @lang）由包装 Taglib 的 runtimeCallback 直接调 buildMarkup。
 */
final class SearchableCodeSelect
{
    /**
     * 编译期片段：ACC 解析声明式属性后 echo buildMarkup。
     *
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

        $attrs = $attributes;
        unset($attrs['id'], $attrs['name'], $attrs['form'], $attrs['class'], $attrs['style'], $attrs['on-change'], $attrs['allow-empty'], $attrs['clearable']);
        $attrs['id'] = $idLiteral;
        $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);

        $phpOpen = '<' . '?php ';
        $phpClose = '?' . '>';
        $echoOpen = '<' . '?= ';

        return $phpOpen . $code . ' ' . $phpClose . "\n"
            . $echoOpen . '\\' . self::class . '::buildMarkup(['
            . "'id' => " . \var_export($idLiteral, true) . ','
            . "'name' => " . \var_export($nameLiteral, true) . ','
            . "'form' => " . \var_export($formAttr, true) . ','
            . "'class' => " . \var_export($class, true) . ','
            . "'style' => " . \var_export($style, true) . ','
            . "'on-change' => " . \var_export($onChange, true) . ','
            . "'allow-empty' => " . \var_export($allowEmpty, true) . ','
            . "'clearable' => " . \var_export($clearable, true) . ','
            . "'component' => " . \var_export($component, true) . ','
            . "'api-ns' => " . \var_export($apiNs, true) . ','
            . "'default-empty-label' => " . \var_export($defaultEmpty, true) . ','
            . "'default-placeholder' => " . \var_export($defaultPlaceholder, true) . ','
            . "'not-found' => " . \var_export($notFound, true) . ','
            . "'value' => \$Taglib__value ?? '',"
            . "'options' => \$Taglib__options ?? null,"
            . "'empty-label' => \$Taglib__empty_label ?? '',"
            . "'placeholder' => \$Taglib__placeholder ?? '',"
            . ']) ' . $phpClose;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function buildMarkup(array $config): string
    {
        $id = \trim((string)($config['id'] ?? ''));
        $name = \trim((string)($config['name'] ?? ''));
        $form = \trim((string)($config['form'] ?? ''));
        $class = (string)($config['class'] ?? '');
        $style = (string)($config['style'] ?? '');
        $onChange = (string)($config['on-change'] ?? '');
        $allowEmpty = (bool)($config['allow-empty'] ?? true);
        $clearable = (bool)($config['clearable'] ?? false);
        $component = (string)($config['component'] ?? 'code-select');
        $apiNs = (string)($config['api-ns'] ?? 'WelineCodeSelect');
        $defaultEmpty = (string)($config['default-empty-label'] ?? '');
        $defaultPlaceholder = (string)($config['default-placeholder'] ?? '');
        $notFound = (string)($config['not-found'] ?? '');
        $clearTitle = (string)__('清空');

        $value = \trim((string)($config['value'] ?? ''));
        $emptyLabel = \trim((string)($config['empty-label'] ?? ''));
        if ($emptyLabel === '') {
            $emptyLabel = $defaultEmpty;
        }
        $placeholder = \trim((string)($config['placeholder'] ?? ''));
        if ($placeholder === '') {
            $placeholder = $defaultPlaceholder;
        }

        $optionsRaw = $config['options'] ?? null;
        $options = [];
        if (\is_string($optionsRaw) && $optionsRaw !== '') {
            $decoded = \json_decode($optionsRaw, true);
            if (\is_array($decoded)) {
                $options = $decoded;
            }
        } elseif (\is_array($optionsRaw)) {
            $options = $optionsRaw;
        }
        $normalized = [];
        foreach ($options as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $val = (string)($row['value'] ?? $row['code'] ?? $row['id'] ?? '');
            if ($val === '') {
                continue;
            }
            $normalized[] = [
                'value' => $val,
                'label' => (string)($row['label'] ?? $row['name'] ?? $val),
                'meta' => (string)($row['meta'] ?? $row['code'] ?? ''),
            ];
        }
        $display = '';
        foreach ($normalized as $row) {
            if (\hash_equals((string)$row['value'], $value)) {
                $display = (string)$row['label'];
                break;
            }
        }

        $h = static fn(string $s): string => \htmlspecialchars($s, ENT_QUOTES);
        $j = static fn(mixed $v): string => \json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $html = [];
        $html[] = '<style>';
        $html[] = '.weline-code-select{position:relative;min-width:160px;max-width:100%;width:100%;color:var(--weline-theme-text,var(--backend-color-text-primary,#162033))}';
        $html[] = '.weline-code-select-trigger{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;box-sizing:border-box;min-height:var(--weline-control-height);height:var(--weline-control-height);padding:0 var(--weline-space-3);background:var(--weline-theme-surface,var(--backend-color-card-bg,#fff));border:1px solid var(--weline-theme-border-strong,var(--backend-color-border-default,#dbe3ef));border-radius:var(--weline-radius-md,6px);color:var(--weline-theme-text,var(--backend-color-text-primary,#162033));text-align:left;cursor:pointer}';
        $html[] = '.weline-code-select-trigger:hover,.weline-code-select.is-open .weline-code-select-trigger{border-color:var(--weline-theme-primary,var(--backend-color-primary,#556ee6));box-shadow:var(--weline-theme-focus-ring,0 0 0 3px color-mix(in srgb,var(--weline-theme-primary,#556ee6) 26%,transparent));outline:0}';
        $html[] = '.weline-code-select-label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.9rem}';
        $html[] = '.weline-code-select-label.is-empty{color:var(--weline-theme-text-subtle,var(--backend-color-text-secondary,#64748b))}';
        $html[] = '.weline-code-select-actions{display:inline-flex;align-items:center;gap:4px;flex:0 0 auto}';
        $html[] = '.weline-code-select-clear{border:0;background:transparent;color:var(--weline-theme-text-subtle,#94a3b8);cursor:pointer;font-size:16px;line-height:1;padding:0}';
        $html[] = '.weline-code-select-clear:hover{color:var(--weline-theme-danger,#ef4444)}';
        $html[] = '.weline-code-select-chevron{color:var(--weline-theme-text-muted,var(--backend-color-text-secondary,#64748b));font-size:16px;line-height:1}';
        $html[] = '.weline-code-select-dropdown{display:none;padding:8px;background:var(--weline-theme-surface-raised,var(--weline-theme-surface,var(--backend-color-card-bg,#fff)));border:1px solid var(--weline-theme-border,var(--backend-color-border-default,#dbe3ef));border-radius:var(--weline-radius-lg,10px);box-shadow:var(--weline-theme-shadow-md,0 16px 36px rgba(15,23,42,.16));box-sizing:border-box;overflow:hidden;color:var(--weline-theme-text,var(--backend-color-text-primary,#162033))}';
        $html[] = '.weline-code-select-search{display:block;width:100%;min-height:36px;padding:6px 10px;margin:0 0 7px;border:1px solid var(--weline-theme-border,var(--backend-color-border-default,#dbe3ef));border-radius:var(--weline-radius-sm,6px);background:var(--weline-theme-surface-muted,var(--backend-color-bg-secondary,#f8fafc));color:var(--weline-theme-text,var(--backend-color-text-primary,#162033));box-sizing:border-box}';
        $html[] = '.weline-code-select-search::placeholder{color:var(--weline-theme-text-subtle,var(--backend-color-text-secondary,#64748b))}';
        $html[] = '.weline-code-select-search:focus{border-color:var(--weline-theme-focus,var(--weline-theme-primary,#556ee6));box-shadow:var(--weline-theme-focus-ring,0 0 0 3px color-mix(in srgb,var(--weline-theme-primary,#556ee6) 26%,transparent));outline:0}';
        $html[] = '.weline-code-select-list{max-height:280px;overflow:auto;overscroll-behavior:contain}';
        $html[] = '.weline-code-select-item{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:8px 10px;border:0;border-radius:var(--weline-radius-sm,7px);background:transparent;color:var(--weline-theme-text,var(--backend-color-text-primary,#162033));text-align:left;cursor:pointer}';
        $html[] = '.weline-code-select-item:hover,.weline-code-select-item.is-selected{background:var(--weline-theme-surface-hover,var(--backend-color-bg-tertiary,#f1f5f9));outline:0}';
        $html[] = '.weline-code-select-item.is-selected{background:var(--weline-theme-primary-surface,color-mix(in srgb,var(--weline-theme-primary,#556ee6) 14%,transparent));outline:0}';
        $html[] = '.weline-code-select-item-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.85rem}';
        $html[] = '.weline-code-select-item-meta{color:var(--weline-theme-text-muted,var(--backend-color-text-secondary,#64748b));font-size:11px;white-space:nowrap}';
        $html[] = '.weline-code-select-empty{padding:10px;text-align:center;color:var(--weline-theme-text-muted,var(--backend-color-text-secondary,#64748b));font-size:.85rem}';
        $html[] = '</style>';


        $emptyClass = $display === '' ? ' is-empty' : '';
        $labelText = $display !== '' ? $display : $emptyLabel;
        $html[] = '<div class="weline-code-select ' . $h($class) . '" style="' . $h($style) . '" id="' . $h($id) . '_wrapper" data-component="' . $h($component) . '">';
        $html[] = '  <button type="button" class="weline-code-select-trigger" id="' . $h($id) . '_trigger" aria-haspopup="listbox" aria-expanded="false">';
        $html[] = '    <span class="weline-code-select-label' . $emptyClass . '" id="' . $h($id) . '_display">' . $h($labelText) . '</span>';
        $html[] = '    <span class="weline-code-select-actions">';
        if ($clearable) {
            $html[] = '      <span class="weline-code-select-clear" id="' . $h($id) . '_clear" title="' . $h($clearTitle) . '" hidden>&times;</span>';
        }
        $html[] = '      <span class="weline-code-select-chevron" aria-hidden="true">⌄</span>';
        $html[] = '    </span>';
        $html[] = '  </button>';
        $formAttrHtml = $form !== '' ? ' form="' . $h($form) . '"' : '';
        $html[] = '  <input type="hidden" id="' . $h($id) . '" name="' . $h($name) . '" value="' . $h($value) . '"' . $formAttrHtml . ' data-code-select-value>';
        $html[] = '  <div class="weline-code-select-dropdown" id="' . $h($id) . '_dropdown" hidden>';
        $html[] = '    <input type="search" class="weline-code-select-search" id="' . $h($id) . '_search" placeholder="' . $h($placeholder) . '" autocomplete="off">';
        $html[] = '    <div class="weline-code-select-list" id="' . $h($id) . '_list" role="listbox"></div>';
        $html[] = '  </div>';
        $html[] = '</div>';

        $html[] = \Weline\Framework\View\Taglib\Support\FloatingDropdownEmitter::script();
        $html[] = '<script>(function(){';
        $html[] = '"use strict";';
        $html[] = 'var id = ' . $j((string)$id) . ';';
        $html[] = 'var emptyLabel = ' . $j((string)$emptyLabel) . ';';
        $html[] = 'var notFound = ' . $j($notFound) . ';';
        $html[] = 'var allowEmpty = ' . ($allowEmpty ? 'true' : 'false') . ';';
        $html[] = 'var clearable = ' . ($clearable ? 'true' : 'false') . ';';
        $html[] = 'var onChangeCode = ' . $j($onChange) . ';';
        $html[] = 'var apiNs = ' . $j($apiNs) . ';';
        $html[] = 'var options = ' . $j($normalized) . ';';
        $html[] = 'var wrapper = document.getElementById(id + "_wrapper");';
        $html[] = 'var trigger = document.getElementById(id + "_trigger");';
        $html[] = 'var dropdown = document.getElementById(id + "_dropdown");';
        $html[] = 'var search = document.getElementById(id + "_search");';
        $html[] = 'var list = document.getElementById(id + "_list");';
        $html[] = 'var display = document.getElementById(id + "_display");';
        $html[] = 'var hidden = document.getElementById(id);';
        $html[] = 'var clearBtn = document.getElementById(id + "_clear");';
        $html[] = 'if (!wrapper || !trigger || !dropdown || !search || !list || !display || !hidden) return;';
        $html[] = 'var selected = String(hidden.value == null ? "" : hidden.value);';
        $html[] = 'var open = false;';
        $html[] = 'function labelOf(value){';
        $html[] = '  for (var i=0;i<options.length;i++){ if (String(options[i].value) === String(value)) return options[i].label; }';
        $html[] = '  return "";';
        $html[] = '}';
        $html[] = 'function syncDisplay(){';
        $html[] = '  var label = labelOf(selected);';
        $html[] = '  if (label) { display.textContent = label; display.classList.remove("is-empty"); }';
        $html[] = '  else { display.textContent = emptyLabel; display.classList.add("is-empty"); }';
        $html[] = '  if (clearBtn) clearBtn.hidden = !(clearable && selected !== "");';
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
        $html[] = '  selected = String(value == null ? "" : value);';
        $html[] = '  hidden.value = selected;';
        $html[] = '  syncDisplay();';

        $html[] = '})();</script>';

        return \implode("\n", $html);
    }
}
