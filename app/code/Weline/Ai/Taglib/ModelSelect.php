<?php

declare(strict_types=1);

namespace Weline\Ai\Taglib;

use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;

/**
 * Declarative AI model selector. Behaviour and styling are owned by the route bundle.
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
            $allSuppliers = htmlspecialchars((string)__('全部供应商'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $defaultModel = htmlspecialchars((string)__('使用默认模型'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $hint = htmlspecialchars((string)__('先选择供应商，再搜索并选择模型'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $noMatch = htmlspecialchars((string)__('未找到匹配的模型'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $loadFail = htmlspecialchars((string)__('模型列表加载失败'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $loading = htmlspecialchars((string)__('正在加载模型...'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $supplierLabel = htmlspecialchars((string)__('供应商筛选'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return <<<HTML
<?php {$compiled}
\$Taglib__escape = static fn(mixed \$value): string => htmlspecialchars((string)\$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
\$Taglib__dom_id = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)\$Taglib__id) ?: 'w-ai-model';
?>
<div
    class="w-ai-model-select"
    data-w-component="ai-model-select"
    data-ai-model-limit="<?= max(1, min(200, (int)\$Taglib__limit)) ?>"
    data-ai-model-no-match="{$noMatch}"
    data-ai-model-load-fail="{$loadFail}"
    data-ai-model-loading="{$loading}"
>
    <button
        type="button"
        class="w-button w-ai-model-select__trigger"
        id="<?= \$Taglib__escape(\$Taglib__dom_id) ?>-trigger"
        data-tone="neutral"
        data-w-ai-model-trigger
        aria-controls="<?= \$Taglib__escape(\$Taglib__dom_id) ?>-panel"
        aria-expanded="false"
    >
        <span data-w-ai-model-display><?= trim((string)\$Taglib__display) !== '' ? \$Taglib__escape(\$Taglib__display) : '{$defaultModel}' ?></span>
        <w-icon name="chevron-down" size="xs"></w-icon>
    </button>
    <div
        class="w-combobox__panel w-ai-model-select__panel"
        id="<?= \$Taglib__escape(\$Taglib__dom_id) ?>-panel"
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
            <span class="w-visually-hidden"><?= \$Taglib__escape(\$Taglib__placeholder) ?></span>
            <input
                class="w-input"
                type="search"
                role="combobox"
                data-w-ai-model-search
                autocomplete="off"
                placeholder="<?= \$Taglib__escape(\$Taglib__placeholder) ?>"
                aria-controls="<?= \$Taglib__escape(\$Taglib__dom_id) ?>-list"
                aria-expanded="false"
            >
        </label>
        <input
            type="hidden"
            name="<?= \$Taglib__escape(\$Taglib__name) ?>"
            value="<?= \$Taglib__escape(\$Taglib__value) ?>"
            data-ai-model-value
            data-service-type="<?= \$Taglib__escape(\$Taglib__service_type) ?>"
        >
        <div class="w-ai-model-select__status" data-w-ai-model-status hidden></div>
        <div class="w-ai-model-select__list" id="<?= \$Taglib__escape(\$Taglib__dom_id) ?>-list" data-w-ai-model-list role="listbox"></div>
    </div>
    <small class="w-field__hint">{$hint}</small>
</div>
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
            '<w:ai:model:select id="model_id" name="model_code" value="modelCode" display="modelName" service_type="serviceType" />',
            ENT_NOQUOTES,
        );
    }
}
