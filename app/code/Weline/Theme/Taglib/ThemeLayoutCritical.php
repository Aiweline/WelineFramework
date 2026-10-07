<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * theme:layout-critical — inline layout shell CSS in &lt;head&gt; before external stylesheets.
 *
 * &lt;theme:layout-critical area="frontend"/&gt;
 * &lt;w:theme:layout-critical area="backend"/&gt;
 */
final class ThemeLayoutCritical implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:layout-critical';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'area' => true,
        ];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            unset($tagKey, $config, $tagData);

            $attrs = is_array($attributes) ? $attributes : [];
            $areaExpr = isset($attrs['area']) && is_string($attrs['area']) && $attrs['area'] !== ''
                && !str_contains($attrs['area'], '<?') && !str_contains($attrs['area'], '$')
                ? var_export($attrs['area'], true)
                : '(string)($Taglib__area ?? \'frontend\')';

            return '<?php echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance('
                . '\\Weline\\Theme\\Service\\Theme\\LayoutCriticalCssService::class'
                . ')->renderInlineStyle(' . $areaExpr . '); ?>';
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
        return <<<'DOC'
内联 layout-critical CSS，避免外链布局样式未就绪时的页壳变形。

&lt;theme:layout-critical area="frontend"/&gt;
活动主题可在 theme/{area}/assets/css/layout-critical.css 覆盖基线。
DOC;
    }
}
