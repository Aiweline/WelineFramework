<?php

declare(strict_types=1);

namespace Weline\Dropship\Taglib;

use Weline\Framework\Taglib\TaglibInterface;
use Weline\Theme\Taglib\SearchSelect;

/**
 * 货源供应商选择（Theme SearchSelect；默认多选 chips）。
 *
 * <w:dropship:provider:select
 *     id="dropship-listed-providers"
 *     name="sources"
 *     options-json="providerSelectOptionsJson"
 *     value="selectedSourcesCsv"
 *     multiple="true"
 *     clearable="true"
 *     auto-submit="true"
 *     form="dropship-listed-filter"
 * />
 *
 * 空值语义：全部供应商（不做 IN 过滤）。多选值为逗号分隔 code。
 */
final class ProviderSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'dropship:provider:select';
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
            'name' => false,
            'value' => false,
            'options' => false,
            'options-json' => false,
            'placeholder' => false,
            'multiple' => false,
            'clearable' => false,
            'required' => false,
            'disabled' => false,
            'class' => false,
            'style' => false,
            'auto-submit' => false,
            'form' => false,
            'limit' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes) {
            unset($tag_key, $config, $tag_data);
            $attributes = is_array($attributes) ? $attributes : [];
            $id = trim((string)($attributes['id'] ?? ''));
            if ($id === '') {
                throw new \Exception((string)__('id属性不能为空'));
            }
            $name = trim((string)($attributes['name'] ?? 'sources'));
            if ($name === '') {
                $name = 'sources';
            }
            $multipleRaw = strtolower(trim((string)($attributes['multiple'] ?? 'true')));
            $isMultiple = !in_array($multipleRaw, ['false', '0', 'no'], true);
            $clearableRaw = strtolower(trim((string)($attributes['clearable'] ?? 'true')));
            $clearable = !in_array($clearableRaw, ['false', '0', 'no'], true);
            $autoSubmitRaw = strtolower(trim((string)($attributes['auto-submit'] ?? 'false')));
            $autoSubmit = in_array($autoSubmitRaw, ['true', '1', 'yes'], true);
            $formId = trim((string)($attributes['form'] ?? ''));

            $attrs = $attributes;
            unset(
                $attrs['id'],
                $attrs['name'],
                $attrs['multiple'],
                $attrs['clearable'],
                $attrs['auto-submit'],
                $attrs['form'],
            );
            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attrs);
            $placeholderDefault = (string)__('搜索或选择供应商（留空=全部）');
            $idJs = json_encode($id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $formJs = json_encode($formId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $autoJs = $autoSubmit ? 'true' : 'false';

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';
            $html[] = '<?php $__dps_placeholder = trim((string)($Taglib__placeholder ?? \'\'));'
                . ' if ($__dps_placeholder === \'\') { $__dps_placeholder = ' . var_export($placeholderDefault, true) . '; } ?>';
            $html[] = '<?= \\' . SearchSelect::class . '::buildMarkup(['
                . "'id' => " . var_export($id, true) . ','
                . "'name' => " . var_export($name, true) . ','
                . "'options-json' => (string)(\$Taglib__options_json ?? ''),"
                . "'options' => (string)(\$Taglib__options ?? ''),"
                . "'value' => (string)(\$Taglib__value ?? ''),"
                . "'placeholder' => (string)\$__dps_placeholder,"
                . "'class' => (string)(\$Taglib__class ?? ''),"
                . "'style' => (string)(\$Taglib__style ?? ''),"
                . "'disabled' => (string)(\$Taglib__disabled ?? ''),"
                . "'required' => (string)(\$Taglib__required ?? ''),"
                . "'clearable' => " . var_export($clearable ? 'true' : 'false', true) . ','
                . "'multiple' => " . var_export($isMultiple ? 'true' : 'false', true) . ','
                . "'limit' => (string)(\$Taglib__limit ?? '50'),"
                . "'min-chars' => '0',"
                . "'value-field' => 'value',"
                . "'label-field' => 'label',"
                . ']) ?>';

            if ($autoSubmit) {
                $html[] = <<<HTML
<script data-w-dropship-provider-select-auto="1">(function(){
  var id = {$idJs};
  var formId = {$formJs};
  var auto = {$autoJs};
  if (!auto) { return; }
  function bind() {
    var hidden = document.getElementById(id + '_value');
    if (!hidden || hidden.getAttribute('data-w-dropship-provider-auto') === '1') { return; }
    hidden.setAttribute('data-w-dropship-provider-auto', '1');
    hidden.addEventListener('change', function () {
      var form = formId ? document.getElementById(formId) : (hidden.form || hidden.closest('form'));
      if (form && typeof form.requestSubmit === 'function') { form.requestSubmit(); }
      else if (form) { form.submit(); }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();</script>
HTML;
            }

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
            '<h3><code>&lt;w:dropship:provider:select&gt;</code></h3>'
            . '<p>货源供应商可搜索选择；默认 <code>multiple</code> chips。空值=全部；多选值为逗号分隔 code。'
            . '选项 <code>options-json</code>；可选 <code>auto-submit</code>。</p>',
            ENT_NOQUOTES,
        );
    }
}
