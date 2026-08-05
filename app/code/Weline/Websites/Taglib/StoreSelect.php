<?php

declare(strict_types=1);

namespace Weline\Websites\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * Store 选择标签（可搜索单选）。
 *
 * <w:websites:store:select
 *     id="wsc-store-code"
 *     name="store_code"
 *     value="targetStore"
 *     options="wscStoreOptionsJson"
 *     allow-empty="true"
 *     empty-label="wscStoreEmpty"
 *     placeholder="wscStorePlaceholder"
 * />
 */
class StoreSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'websites:store:select';
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

            $allowEmptyRaw = (string)($attributes['allow-empty'] ?? 'true');
            $allowEmpty = \in_array(\strtolower(\trim($allowEmptyRaw)), ['true', '1', 'yes', ''], true);
            $clearableRaw = (string)($attributes['clearable'] ?? ($allowEmpty ? 'true' : 'false'));
            $clearable = \in_array(\strtolower(\trim($clearableRaw)), ['true', '1', 'yes'], true);

            return SearchableCodeSelect::render([
                'id' => (string)$attributes['id'],
                'name' => (string)($attributes['name'] ?? 'store_code'),
                'class' => (string)($attributes['class'] ?? ''),
                'style' => (string)($attributes['style'] ?? ''),
                'form' => (string)($attributes['form'] ?? ''),
                'on-change' => (string)($attributes['on-change'] ?? ''),
                'allow-empty' => $allowEmpty,
                'clearable' => $clearable,
                'component' => 'store-select',
                'api-ns' => 'WelineStoreSelect',
                'default-empty-label' => (string)__('Website 层'),
                'default-placeholder' => (string)__('搜索 Store'),
                'not-found' => (string)__('未找到匹配 Store'),
                'attributes' => $attributes,
            ]);
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
            '<h3><code>&lt;w:websites:store:select&gt;</code></h3><p>Store 选择标签，支持搜索、可空「Website 层」、form 关联与 on-change。</p>',
            ENT_NOQUOTES
        );
    }
}
