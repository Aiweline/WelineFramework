<?php

declare(strict_types=1);

namespace Weline\Inventory\Taglib;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Taglib\SearchSelect;

/**
 * 本地仓库选择标签（可搜索单选）。
 *
 * <w:inventory:warehouse:select
 *     id="local-wh"
 *     name="local_warehouse_id"
 *     website-id="website_id"
 *     required="true"
 * />
 */
final class WarehouseSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'inventory:warehouse:select';
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
            'website-id' => false,
            'placeholder' => false,
            'class' => false,
            'style' => false,
            'disabled' => false,
            'required' => false,
            'clearable' => false,
            'limit' => false,
            'options-json' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes) {
            unset($tag_key, $config, $tag_data);
            $attributes = is_array($attributes) ? $attributes : [];
            if (empty($attributes['id'])) {
                throw new \Exception((string)__('id属性不能为空'));
            }
            $code = AttributeCodeCompiler::attributes($attributes);

            return '<?php ' . $code . ' ?>' . "\n"
                . '<?= \\' . self::class . '::buildMarkup(['
                . "'id' => (string)(\$Taglib__id ?? ''),"
                . "'name' => (string)(\$Taglib__name ?? 'local_warehouse_id'),"
                . "'value' => (string)(\$Taglib__value ?? ''),"
                . "'website-id' => (string)(\$Taglib__website_id ?? ''),"
                . "'placeholder' => (string)(\$Taglib__placeholder ?? ''),"
                . "'class' => (string)(\$Taglib__class ?? ''),"
                . "'style' => (string)(\$Taglib__style ?? ''),"
                . "'disabled' => (string)(\$Taglib__disabled ?? ''),"
                . "'required' => (string)(\$Taglib__required ?? ''),"
                . "'clearable' => (string)(\$Taglib__clearable ?? 'true'),"
                . "'limit' => (string)(\$Taglib__limit ?? '30'),"
                . "'options-json' => (string)(\$Taglib__options_json ?? ''),"
                . ']) ?>';
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
        $id = trim((string)($attributes['id'] ?? ''));
        if ($id === '') {
            throw new \Exception((string)__('id属性不能为空'));
        }
        $name = trim((string)($attributes['name'] ?? 'local_warehouse_id')) ?: 'local_warehouse_id';
        $websiteRaw = trim((string)($attributes['website-id'] ?? ''));
        /** @var Url $urlBuilder */
        $urlBuilder = ObjectManager::getInstance(Url::class);
        $url = $urlBuilder->getBackendUrl('weline_inventory/backend/warehouse-search/search');
        if ($websiteRaw !== '' && ctype_digit($websiteRaw)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'website_id=' . (int)$websiteRaw;
        }
        $placeholder = trim((string)($attributes['placeholder'] ?? ''));
        if ($placeholder === '') {
            $placeholder = (string)__('搜索本地仓库代码或名称');
        }

        return SearchSelect::buildMarkup([
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'value' => (string)($attributes['value'] ?? ''),
            'placeholder' => $placeholder,
            'class' => (string)($attributes['class'] ?? ''),
            'style' => (string)($attributes['style'] ?? ''),
            'disabled' => (string)($attributes['disabled'] ?? ''),
            'required' => (string)($attributes['required'] ?? ''),
            'clearable' => (string)($attributes['clearable'] ?? 'true'),
            'limit' => (string)($attributes['limit'] ?? '30'),
            'min-chars' => '0',
            'value-field' => 'value',
            'label-field' => 'label',
            'options-json' => (string)($attributes['options-json'] ?? ''),
        ]);
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
            '<h3><code>&lt;w:inventory:warehouse:select&gt;</code></h3>'
            . '<p>本地仓库可搜索单选；数据源 <code>weline_inventory/backend/warehouse-search/search</code>。</p>',
            ENT_NOQUOTES,
        );
    }
}
