<?php

declare(strict_types=1);

namespace Weline\Customer\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 后台客户选择标签（可搜索单选，默认单选）。
 *
 * <w:customer:admin:select
 *     id="affiliate-customer-id"
 *     name="customer_id"
 *     value="editingCustomerId"
 *     display="editingCustomerLabel"
 *     placeholder="@lang(搜索客户姓名或邮箱)"
 * />
 */
final class AdminCustomerSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'customer:admin:select';
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
            'display' => false,
            'class' => false,
            'style' => false,
            'placeholder' => false,
            'empty-label' => false,
            'allow-empty' => false,
            'clearable' => false,
            'disabled' => false,
            'required' => false,
            'form' => false,
            'on-change' => false,
            'limit' => false,
            'multiple' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception((string) __('id属性不能为空'));
            }

            $multipleRaw = (string) ($attributes['multiple'] ?? 'false');
            if (\in_array(\strtolower(\trim($multipleRaw)), ['true', '1', 'yes'], true)) {
                throw new \Exception((string) __('customer:admin:select 当前仅支持单选'));
            }

            $allowEmptyRaw = (string) ($attributes['allow-empty'] ?? 'false');
            $allowEmpty = \in_array(\strtolower(\trim($allowEmptyRaw)), ['true', '1', 'yes'], true);
            $clearableRaw = (string) ($attributes['clearable'] ?? ($allowEmpty ? 'true' : 'false'));
            $clearable = \in_array(\strtolower(\trim($clearableRaw)), ['true', '1', 'yes'], true);
            $disabledRaw = (string) ($attributes['disabled'] ?? 'false');
            $disabled = \in_array(\strtolower(\trim($disabledRaw)), ['true', '1', 'yes'], true);
            $requiredRaw = (string) ($attributes['required'] ?? 'false');
            $required = \in_array(\strtolower(\trim($requiredRaw)), ['true', '1', 'yes'], true);
            $limit = max(1, min(50, (int) ($attributes['limit'] ?? 20)));

            return SearchableCustomerSelect::render([
                'id' => (string) $attributes['id'],
                'name' => (string) ($attributes['name'] ?? 'customer_id'),
                'class' => (string) ($attributes['class'] ?? ''),
                'style' => (string) ($attributes['style'] ?? ''),
                'form' => (string) ($attributes['form'] ?? ''),
                'on-change' => (string) ($attributes['on-change'] ?? ''),
                'allow-empty' => $allowEmpty,
                'clearable' => $clearable && !$disabled,
                'disabled' => $disabled,
                'required' => $required,
                'limit' => $limit,
                'default-empty-label' => (string) __('请选择客户'),
                'default-placeholder' => (string) __('搜索姓名或邮箱'),
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
            '<h3><code>&lt;w:customer:admin:select&gt;</code></h3>'
            . '<p>后台客户单选标签，通过 customer_admin Resource 按姓名 / 邮箱搜索。</p>',
            ENT_NOQUOTES,
        );
    }
}
