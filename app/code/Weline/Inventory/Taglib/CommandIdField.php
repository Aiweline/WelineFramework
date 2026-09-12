<?php

declare(strict_types=1);

namespace Weline\Inventory\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 库存幂等命令 ID 字段（含一键生成）。
 *
 * <w:inventory:command-id:field id="inventory-adjust-command" name="command_id" required="true" />
 */
final class CommandIdField implements TaglibInterface
{
    public static function name(): string
    {
        return 'inventory:command-id:field';
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
            'required' => false,
            'maxlength' => false,
            'class' => false,
            'prefix' => false,
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
            $name = htmlspecialchars(trim((string)($attributes['name'] ?? 'command_id')) ?: 'command_id', ENT_QUOTES, 'UTF-8');
            $idEsc = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
            $btnId = htmlspecialchars($id . '-generate', ENT_QUOTES, 'UTF-8');
            $value = htmlspecialchars((string)($attributes['value'] ?? ''), ENT_QUOTES, 'UTF-8');
            $maxlength = htmlspecialchars((string)($attributes['maxlength'] ?? '96'), ENT_QUOTES, 'UTF-8');
            $prefix = htmlspecialchars(trim((string)($attributes['prefix'] ?? 'adj_')) ?: 'adj_', ENT_QUOTES, 'UTF-8');
            $required = in_array(strtolower(trim((string)($attributes['required'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
            $class = htmlspecialchars(trim('w-input ' . (string)($attributes['class'] ?? '')), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string)__('命令 ID'), ENT_QUOTES, 'UTF-8');
            $help = htmlspecialchars((string)__('幂等键，重复提交同 ID 不会重复改库存'), ENT_QUOTES, 'UTF-8');
            $genLabel = htmlspecialchars((string)__('生成'), ENT_QUOTES, 'UTF-8');
            $reqAttr = $required ? ' required' : '';
            $idJson = self::jsString($id);
            $btnJson = self::jsString($id . '-generate');

            return <<<HTML
<label class="w-field__label" for="{$idEsc}">{$label}</label>
<div class="w-cluster" data-testid="inventory-command-id-field">
  <input id="{$idEsc}" class="{$class}" type="text" name="{$name}" value="{$value}" maxlength="{$maxlength}"{$reqAttr} autocomplete="off" data-inventory-command-id-input>
  <button id="{$btnId}" class="w-button" type="button" data-tone="neutral" data-inventory-command-id-generate data-prefix="{$prefix}">{$genLabel}</button>
</div>
<div class="w-field__help">{$help}</div>
<script>
(function(){
  var input = document.getElementById({$idJson});
  var btn = document.getElementById({$btnJson});
  if (!input || !btn || btn.getAttribute('data-bound') === '1') return;
  btn.setAttribute('data-bound', '1');
  function gen(){
    var prefix = btn.getAttribute('data-prefix') || 'adj_';
    var token = (window.crypto && crypto.randomUUID)
      ? crypto.randomUUID().replace(/-/g, '').slice(0, 16)
      : String(Date.now()) + Math.random().toString(16).slice(2, 8);
    input.value = prefix + token;
    input.dispatchEvent(new Event('input', {bubbles:true}));
  }
  btn.addEventListener('click', function(e){ e.preventDefault(); gen(); });
  if (!String(input.value || '').trim()) gen();
})();
</script>
HTML;
        };
    }

    private static function jsString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""';
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
            '<h3><code>&lt;w:inventory:command-id:field&gt;</code></h3>'
            . '<p>库存调整幂等命令 ID；含一键生成，文案由 Inventory 模块提供。</p>',
            ENT_NOQUOTES,
        );
    }
}
