<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;

/**
 * Load a module font with language (or custom chars) subsetting.
 *
 * Source files live in `{Module}/view/fonts/`.
 * - `Relative/Path.ttf` — defaults to Weline_Theme
 * - `Vendor_Module::Relative/Path.ttf` — explicit module
 */
final class ThemeFont implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:font';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'src' => true,
            'family' => false,
            'lang' => false,
            'chars' => false,
            'weight' => false,
            'style' => false,
            'display' => false,
            'unicode-range' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $code = AttributeCodeCompiler::attributes($attributes);
            // Only pass chars when the attribute is present on THIS tag (avoid leak across tags).
            $charsExpr = array_key_exists('chars', $attributes)
                ? '(string)($Taglib__chars ?? \'\')'
                : "''";

            return '<?php ' . $code
                . ' echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance('
                . '\\Weline\\Theme\\Font\\FontFaceService::class)->renderStyleTag(['
                . '\'src\' => (string)($Taglib__src ?? \'\'),'
                . '\'family\' => (string)($Taglib__family ?? \'\'),'
                . '\'lang\' => (string)($Taglib__lang ?? \'\'),'
                . '\'chars\' => ' . $charsExpr . ','
                . '\'weight\' => (string)($Taglib__weight ?? \'400\'),'
                . '\'style\' => (string)($Taglib__style ?? \'normal\'),'
                . '\'display\' => (string)($Taglib__display ?? \'swap\'),'
                . '\'unicode-range\' => (string)($Taglib__unicode_range ?? \'\'),'
                . ']); ?>';
        };
    }

    public static function document(): string
    {
        return <<<'DOC'
按语言或指定字符子集化加载模块字体（源文件在 Module/view/fonts/）。
省略模块时默认 Weline_Theme；写 Vendor_Module:: 可指定其他模块。

按语言（省略 lang 则跟 State::getLangLocal()）：
<w:theme:font src="NotoSansSC-Regular.ttf" family="Noto Sans SC" weight="400" display="swap" />

只提取属性 chars 里的字符（忽略语言表）：
<w:theme:font src="NotoSansSC-Regular.ttf" family="Brand" chars="仅这些字ABC" weight="700" />

升级时会预热 view/fonts 下全部字体 × 语言；chars 子集在首次渲染时生成并缓存。详见 Theme/doc/theme-font.md
DOC;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
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
}
