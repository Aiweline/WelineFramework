<?php

declare(strict_types=1);

namespace Weline\Websites\Taglib;

use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;

/**
 * Channel 选择标签（可搜索单选）。
 *
 * <w:websites:channel:select
 *     id="wsc-channel-code"
 *     name="channel_code"
 *     value="targetChannel"
 *     options="wscChannelOptionsJson"
 *     allow-empty="true"
 *     empty-label="wscChannelEmpty"
 *     placeholder="wscChannelPlaceholder"
 * />
 *
 * 含 @lang / <?= ?> 等动态属性时走 renderRuntimeTag；必须提供 runtimeCallback。
 */
class ChannelSelect implements TaglibInterface
{
    /** @var list<string> */
    private const SCOPE_ATTRS = ['value', 'options', 'empty-label', 'placeholder'];

    public static function name(): string
    {
        return 'websites:channel:select';
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
        return static function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }

            return SearchableCodeSelect::render(self::toConfig($attributes));
        };
    }

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
            $attrs = TaglibRuntimeScope::resolve($template, \is_array($attributes) ? $attributes : [], self::SCOPE_ATTRS);
            if (empty($attrs['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }

            return SearchableCodeSelect::buildMarkup(self::toConfig($attrs));
        };
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function toConfig(array $attributes): array
    {
        $allowEmpty = TaglibRuntimeScope::isTruthy($attributes['allow-empty'] ?? 'true', true);
        if (\is_string($attributes['allow-empty'] ?? null) && \trim((string)$attributes['allow-empty']) === '') {
            $allowEmpty = true;
        }
        $clearable = TaglibRuntimeScope::isTruthy(
            $attributes['clearable'] ?? ($allowEmpty ? 'true' : 'false'),
            $allowEmpty
        );

        return [
            'id' => (string)$attributes['id'],
            'name' => (string)($attributes['name'] ?? 'channel_code'),
            'class' => (string)($attributes['class'] ?? ''),
            'style' => (string)($attributes['style'] ?? ''),
            'form' => (string)($attributes['form'] ?? ''),
            'on-change' => (string)($attributes['on-change'] ?? ''),
            'allow-empty' => $allowEmpty,
            'clearable' => $clearable,
            'component' => 'channel-select',
            'api-ns' => 'WelineChannelSelect',
            'default-empty-label' => (string)__('Store 层'),
            'default-placeholder' => (string)__('搜索 Channel'),
            'not-found' => (string)__('未找到匹配 Channel'),
            'attributes' => $attributes,
            'value' => $attributes['value'] ?? '',
            'options' => $attributes['options'] ?? null,
            'empty-label' => $attributes['empty-label'] ?? '',
            'placeholder' => $attributes['placeholder'] ?? '',
        ];
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
            '<h3><code>&lt;w:websites:channel:select&gt;</code></h3><p>Channel 选择标签，支持搜索、可空「Store 层」、form 关联与 on-change。</p>',
            ENT_NOQUOTES
        );
    }
}
