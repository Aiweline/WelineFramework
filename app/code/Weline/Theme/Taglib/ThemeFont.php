<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;

/**
 * Load a module font with language (or custom chars) subsetting.
 *
 * Source files live in `{Module}/view/fonts/` and are referenced as
 * `Vendor_Module::Relative/Path.ttf`.
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

            return '<?php ' . $code
                . ' echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance('
                . '\\Weline\\Theme\\Font\\FontFaceService::class)->renderStyleTag(['
                . '\'src\' => (string)($Taglib__src ?? \'\'),'
                . '\'family\' => (string)($Taglib__family ?? \'\'),'
                . '\'lang\' => (string)($Taglib__lang ?? \'\'),'
                . '\'chars\' => (string)($Taglib__chars ?? \'\'),'
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
按语言子集化加载模块字体（源文件放在 Module/view/fonts/）。

示例（省略 lang 则跟 State::getLangLocal()）：
<w:theme:font src="Weline_Theme::NotoSansSC-Regular.ttf" family="Noto Sans SC" weight="400" display="swap" />

指定字符临时子集：
<w:theme:font src="Weline_Theme::Brand.ttf" family="Brand" chars="仅这些字ABC" />

升级时会预热 view/fonts 下全部字体 × 语言；已有子集跳过。详见 Theme/doc/theme-font.md
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
