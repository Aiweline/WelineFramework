<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Service\WebsiteThemeBindingService;

/**
 * 前台主题下拉（Theme SearchSelect）。
 *
 * <w:theme:select
 *     id="scope-theme-id"
 *     name="extensions[theme][theme_id]"
 *     value="4"
 *     allow-empty="true"
 *     empty-label="不修改（保持当前）"
 * />
 */
final class ThemeSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:select';
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
            'options-json' => false,
            'placeholder' => false,
            'empty-label' => false,
            'allow-empty' => false,
            'clearable' => false,
            'class' => false,
            'style' => false,
            'disabled' => false,
            'required' => false,
            'limit' => false,
            'input-attrs' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes) {
            unset($tag_key, $config, $tag_data);
            $attributes = is_array($attributes) ? $attributes : [];
            if (trim((string)($attributes['id'] ?? '')) === '') {
                throw new \Exception((string)__('id属性不能为空'));
            }
            $code = AttributeCodeCompiler::attributes($attributes);

            return '<?php ' . $code . ' ?>' . "\n"
                . '<?= \\' . self::class . '::buildMarkup(['
                . "'id' => (string)(\$Taglib__id ?? ''),"
                . "'name' => (string)(\$Taglib__name ?? 'theme_id'),"
                . "'value' => (string)(\$Taglib__value ?? ''),"
                . "'options-json' => (string)(\$Taglib__options_json ?? ''),"
                . "'placeholder' => (string)(\$Taglib__placeholder ?? ''),"
                . "'empty-label' => (string)(\$Taglib__empty_label ?? ''),"
                . "'allow-empty' => (string)(\$Taglib__allow_empty ?? 'true'),"
                . "'clearable' => (string)(\$Taglib__clearable ?? 'true'),"
                . "'class' => (string)(\$Taglib__class ?? ''),"
                . "'style' => (string)(\$Taglib__style ?? ''),"
                . "'disabled' => (string)(\$Taglib__disabled ?? ''),"
                . "'required' => (string)(\$Taglib__required ?? ''),"
                . "'limit' => (string)(\$Taglib__limit ?? '50'),"
                . "'input-attrs' => (string)(\$Taglib__input_attrs ?? ''),"
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
        $name = trim((string)($attributes['name'] ?? 'theme_id')) ?: 'theme_id';
        $allowEmpty = self::truthy($attributes['allow-empty'] ?? 'true');
        $clearable = self::truthy($attributes['clearable'] ?? ($allowEmpty ? 'true' : 'false'));
        $emptyLabel = trim((string)($attributes['empty-label'] ?? ''));
        if ($emptyLabel === '') {
            $emptyLabel = (string)__('不修改（保持当前）');
        }
        $placeholder = trim((string)($attributes['placeholder'] ?? ''));
        if ($placeholder === '') {
            $placeholder = (string)__('搜索或选择前台主题');
        }

        $optionsJson = trim((string)($attributes['options-json'] ?? ''));
        if ($optionsJson === '') {
            $optionsJson = self::defaultOptionsJson($allowEmpty, $emptyLabel);
        } elseif ($allowEmpty) {
            $optionsJson = self::ensureEmptyOption($optionsJson, $emptyLabel);
        }

        return SearchSelect::buildMarkup([
            'id' => $id,
            'name' => $name,
            'value' => (string)($attributes['value'] ?? ''),
            'options-json' => $optionsJson,
            'placeholder' => $placeholder,
            'class' => (string)($attributes['class'] ?? ''),
            'style' => (string)($attributes['style'] ?? ''),
            'disabled' => (string)($attributes['disabled'] ?? ''),
            'required' => (string)($attributes['required'] ?? ''),
            'clearable' => $clearable ? 'true' : 'false',
            'limit' => (string)($attributes['limit'] ?? '50'),
            'min-chars' => '0',
            'value-field' => 'value',
            'label-field' => 'label',
            'input-attrs' => (string)($attributes['input-attrs'] ?? ''),
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
            '<h3><code>&lt;w:theme:select&gt;</code></h3>'
            . '<p>前台主题可搜索单选；默认列出支持 frontend 的主题。'
            . '<code>allow-empty</code> 时首项为「不修改（保持当前）」value=0。'
            . '可选 <code>options-json</code> 覆盖。</p>',
            ENT_NOQUOTES,
        );
    }

    private static function truthy(mixed $raw): bool
    {
        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function defaultOptionsJson(bool $allowEmpty, string $emptyLabel): string
    {
        /** @var WebsiteThemeBindingService $service */
        $service = ObjectManager::getInstance(WebsiteThemeBindingService::class);
        $opts = [];
        if ($allowEmpty) {
            $opts[] = ['value' => '0', 'label' => $emptyLabel];
        }
        foreach ($service->listFrontendThemes() as $theme) {
            if (!is_array($theme)) {
                continue;
            }
            $id = (int)($theme['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string)($theme['name'] ?? ('#' . $id)));
            $opts[] = ['value' => (string)$id, 'label' => $name . ' (#' . $id . ')'];
        }

        return (string)json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function ensureEmptyOption(string $optionsJson, string $emptyLabel): string
    {
        $decoded = json_decode($optionsJson, true);
        if (!is_array($decoded)) {
            return $optionsJson;
        }
        foreach ($decoded as $row) {
            if (is_array($row) && (string)($row['value'] ?? '') === '0') {
                return $optionsJson;
            }
        }
        array_unshift($decoded, ['value' => '0', 'label' => $emptyLabel]);

        return (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
