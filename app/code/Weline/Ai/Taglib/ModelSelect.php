<?php

declare(strict_types=1);

namespace Weline\Ai\Taglib;

use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;

/**
 * Declarative AI model selector. Behaviour and styling are owned by the route bundle.
 *
 * 动态属性（foreach / embed 内 <?= ?>）走 renderRuntimeTag，必须通过 runtimeCallback 直接输出 HTML。
 */
final class ModelSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'ai:model:select';
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
            'placeholder' => false,
            'limit' => false,
            'service_type' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, array $attributes): string {
            if (empty($attributes['id'])) {
                throw new \InvalidArgumentException(__('id属性不能为空'));
            }

            $attributes += [
                'name' => 'model_code',
                'value' => '',
                'display' => '',
                'placeholder' => __('搜索AI模型...'),
                'limit' => 50,
                'service_type' => '',
            ];
            $attributes['limit'] = max(1, min(200, (int)$attributes['limit']));
            $compiled = AttributeCodeCompiler::attributes($attributes);

            $phpOpen = '<' . '?php ';
            $phpClose = '?' . '>';
            $echoOpen = '<' . '?= ';

            return $phpOpen . $compiled . ' ' . $phpClose . "\n"
                . $echoOpen . '\\' . self::class . '::buildMarkup(['
                . "'id' => (string)(\$Taglib__id ?? ''),"
                . "'name' => (string)(\$Taglib__name ?? 'model_code'),"
                . "'value' => (string)(\$Taglib__value ?? ''),"
                . "'display' => (string)(\$Taglib__display ?? ''),"
                . "'placeholder' => (string)(\$Taglib__placeholder ?? ''),"
                . "'limit' => (int)(\$Taglib__limit ?? 50),"
                . "'service_type' => (string)(\$Taglib__service_type ?? ''),"
                . ']) ' . $phpClose;
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
            unset($template, $content);
            if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs') {
                return '';
            }

            return self::buildMarkup(is_array($attributes) ? $attributes : []);
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function buildMarkup(array $attributes): string
    {
        $decode = static fn($value): string => html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $id = trim($decode($attributes['id'] ?? ''));
        if ($id === '') {
            $id = 'w-ai-model-' . substr(md5(uniqid('', true)), 0, 8);
        }
        $domId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $id) ?: 'w-ai-model';
        $name = $decode($attributes['name'] ?? 'model_code');
        $value = $decode($attributes['value'] ?? '');
        $display = trim($decode($attributes['display'] ?? ''));
        $placeholder = trim($decode($attributes['placeholder'] ?? ''));
        if ($placeholder === '') {
            $placeholder = (string)__('搜索AI模型...');
        }
        $limit = max(1, min(200, (int)$decode($attributes['limit'] ?? '50')));
        $serviceType = $decode($attributes['service_type'] ?? '');

        $allSuppliers = $escape((string)__('全部供应商'));
        $defaultModel = $escape((string)__('使用默认模型'));
        $hint = $escape((string)__('先选择供应商，再搜索并选择模型'));
        $noMatch = $escape((string)__('未找到匹配的模型'));
        $loadFail = $escape((string)__('模型列表加载失败'));
        $loading = $escape((string)__('正在加载模型...'));
        $supplierLabel = $escape((string)__('供应商筛选'));
        $displayText = $display !== '' ? $escape($display) : $defaultModel;

        return <<<HTML
<div
    class="w-ai-model-select"
    data-w-component="ai-model-select"
    data-ai-model-limit="{$limit}"
    data-ai-model-no-match="{$noMatch}"
    data-ai-model-load-fail="{$loadFail}"
    data-ai-model-loading="{$loading}"
>
    <button
        type="button"
        class="w-button w-ai-model-select__trigger"
        id="{$escape($domId)}-trigger"
        data-tone="neutral"
        data-w-ai-model-trigger
        aria-controls="{$escape($domId)}-panel"
        aria-expanded="false"
    >
        <span data-w-ai-model-display>{$displayText}</span>
        <w-icon name="chevron-down" size="xs"></w-icon>
    </button>
    <div
        class="w-combobox__panel w-ai-model-select__panel"
        id="{$escape($domId)}-panel"
        data-w-ai-model-panel
        hidden
    >
        <label class="w-field">
            <span class="w-visually-hidden">{$supplierLabel}</span>
            <select class="w-select" data-w-ai-model-supplier>
                <option value="">{$allSuppliers}</option>
            </select>
        </label>
        <label class="w-field">
            <span class="w-visually-hidden">{$escape($placeholder)}</span>
            <input
                class="w-input"
                type="search"
                role="combobox"
                data-w-ai-model-search
                autocomplete="off"
                placeholder="{$escape($placeholder)}"
                aria-controls="{$escape($domId)}-list"
                aria-expanded="false"
            >
        </label>
        <input
            type="hidden"
            name="{$escape($name)}"
            value="{$escape($value)}"
            data-ai-model-value
            data-service-type="{$escape($serviceType)}"
        >
        <div class="w-ai-model-select__status" data-w-ai-model-status hidden></div>
        <div class="w-ai-model-select__list" id="{$escape($domId)}-list" data-w-ai-model-list role="listbox"></div>
    </div>
    <small class="w-field__hint">{$hint}</small>
</div>
HTML;
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
            '<w:ai:model:select id="model_id" name="model_code" value="modelCode" display="modelName" service_type="serviceType" />',
            ENT_NOQUOTES,
        );
    }
}
