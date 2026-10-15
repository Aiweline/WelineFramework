<?php

declare(strict_types=1);

namespace Weline\Mail\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 后台企业邮箱账号选择（本地 options，可搜索单选标签，对齐 customer:admin:select 交互）。
 *
 * <w:mail:account:select
 *     id="mail-current-account"
 *     name="account"
 *     value="selectedAccountId"
 *     display="selectedMailboxDisplay"
 *     options="mailboxAccountSelectOptionsJson"
 *     auto-submit="true"
 * />
 */
final class AccountSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'mail:account:select';
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
            'options' => false,
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
            'auto-submit' => false,
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
                throw new \Exception((string) __('mail:account:select 当前仅支持单选'));
            }

            $allowEmptyRaw = (string) ($attributes['allow-empty'] ?? 'false');
            $allowEmpty = \in_array(\strtolower(\trim($allowEmptyRaw)), ['true', '1', 'yes'], true);
            $clearableRaw = (string) ($attributes['clearable'] ?? ($allowEmpty ? 'true' : 'false'));
            $clearable = \in_array(\strtolower(\trim($clearableRaw)), ['true', '1', 'yes'], true);
            $disabledRaw = (string) ($attributes['disabled'] ?? 'false');
            $disabled = \in_array(\strtolower(\trim($disabledRaw)), ['true', '1', 'yes'], true);
            $requiredRaw = (string) ($attributes['required'] ?? 'false');
            $required = \in_array(\strtolower(\trim($requiredRaw)), ['true', '1', 'yes'], true);
            $autoSubmitRaw = (string) ($attributes['auto-submit'] ?? 'false');
            $autoSubmit = \in_array(\strtolower(\trim($autoSubmitRaw)), ['true', '1', 'yes'], true);

            return SearchableAccountSelect::render([
                'id' => (string) $attributes['id'],
                'name' => (string) ($attributes['name'] ?? 'account'),
                'class' => (string) ($attributes['class'] ?? ''),
                'style' => (string) ($attributes['style'] ?? ''),
                'form' => (string) ($attributes['form'] ?? ''),
                'on-change' => (string) ($attributes['on-change'] ?? ''),
                'allow-empty' => $allowEmpty,
                'clearable' => $clearable && !$disabled,
                'disabled' => $disabled,
                'required' => $required,
                'auto-submit' => $autoSubmit,
                'default-empty-label' => (string) __('请选择邮箱'),
                'default-placeholder' => (string) __('搜索邮箱或显示名'),
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
            '<h3><code>&lt;w:mail:account:select&gt;</code></h3>'
            . '<p>后台企业邮箱账号单选标签：本地 options、可搜索、标签展示；可选 auto-submit 提交关联表单。</p>',
            ENT_NOQUOTES,
        );
    }
}
