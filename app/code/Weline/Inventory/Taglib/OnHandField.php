<?php

declare(strict_types=1);

namespace Weline\Inventory\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 在手库存件数输入字段（Inventory 拥有文案与校验提示）。
 *
 * <w:inventory:on-hand:field id="inventory-adjust-on-hand" name="on_hand_minor" required="true" />
 */
final class OnHandField implements TaglibInterface
{
    public static function name(): string
    {
        return 'inventory:on-hand:field';
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
            'min' => false,
            'class' => false,
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
            $name = htmlspecialchars(trim((string)($attributes['name'] ?? 'on_hand_minor')) ?: 'on_hand_minor', ENT_QUOTES, 'UTF-8');
            $idEsc = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
            $value = htmlspecialchars((string)($attributes['value'] ?? ''), ENT_QUOTES, 'UTF-8');
            $min = htmlspecialchars((string)($attributes['min'] ?? '0'), ENT_QUOTES, 'UTF-8');
            $required = in_array(strtolower(trim((string)($attributes['required'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
            $class = htmlspecialchars(trim('w-input ' . (string)($attributes['class'] ?? '')), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string)__('在手库存（件）'), ENT_QUOTES, 'UTF-8');
            $help = htmlspecialchars((string)__('单位为件，不是金额'), ENT_QUOTES, 'UTF-8');
            $reqAttr = $required ? ' required' : '';

            return <<<HTML
<label class="w-field__label" for="{$idEsc}">{$label}</label>
<input id="{$idEsc}" class="{$class}" type="number" min="{$min}" name="{$name}" value="{$value}"{$reqAttr} data-testid="inventory-on-hand-field">
<div class="w-field__help">{$help}</div>
HTML;
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
            '<h3><code>&lt;w:inventory:on-hand:field&gt;</code></h3>'
            . '<p>在手库存件数输入；标签与帮助文案由 Inventory 模块提供。</p>',
            ENT_NOQUOTES,
        );
    }
}
